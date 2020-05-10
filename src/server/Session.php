<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\server;

use raklib\generic\ReceiveReliabilityLayer;
use raklib\generic\SendReliabilityLayer;
use raklib\protocol\ACK;
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectedPong;
use raklib\protocol\ConnectionRequest;
use raklib\protocol\ConnectionRequestAccepted;
use raklib\protocol\Datagram;
use raklib\protocol\DisconnectionNotification;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NACK;
use raklib\protocol\NewIncomingConnection;
use raklib\protocol\Packet;
use raklib\protocol\PacketReliability;
use raklib\utils\InternetAddress;
use function count;
use function microtime;
use function ord;

class Session{
	public const MAX_SPLIT_PART_COUNT = 128;
	public const MAX_CONCURRENT_SPLIT_COUNT = 4;

	public const STATE_CONNECTING = 0;
	public const STATE_CONNECTED = 1;
	public const STATE_DISCONNECTING = 2;
	public const STATE_DISCONNECTED = 3;

	public const MIN_MTU_SIZE = 400;

	/** @var Server */
	private $server;

	/** @var \Logger */
	private $logger;

	/** @var InternetAddress */
	private $address;

	/** @var int */
	private $state = self::STATE_CONNECTING;

	/** @var int */
	private $id;

	/** @var float */
	private $lastUpdate;
	/** @var float|null */
	private $disconnectionTime;

	/** @var bool */
	private $isTemporal = true;

	/** @var bool */
	private $isActive = false;

	/** @var float */
	private $lastPingTime = -1;
	/** @var int */
	private $lastPingMeasure = 1;

	/** @var int */
	private $internalId;

	/** @var ReceiveReliabilityLayer */
	private $recvLayer;

	/** @var SendReliabilityLayer */
	private $sendLayer;

	public function __construct(Server $server, \Logger $logger, InternetAddress $address, int $clientId, int $mtuSize, int $internalId){
		if($mtuSize < self::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . self::MIN_MTU_SIZE . ", got $mtuSize");
		}
		$this->server = $server;
		$this->logger = new \PrefixedLogger($logger, "Session: " . $address->toString());
		$this->address = $address;
		$this->id = $clientId;

		$this->lastUpdate = microtime(true);

		$this->internalId = $internalId;

		$this->recvLayer = new ReceiveReliabilityLayer(
			$this->logger,
			function(EncapsulatedPacket $pk) : void{
				$this->handleEncapsulatedPacketRoute($pk);
			},
			function(AcknowledgePacket $pk) : void{
				$this->sendPacket($pk);
			},
			self::MAX_SPLIT_PART_COUNT,
			self::MAX_CONCURRENT_SPLIT_COUNT
		);
		$this->sendLayer = new SendReliabilityLayer(
			$mtuSize,
			function(Datagram $datagram) : void{
				$this->sendPacket($datagram);
			},
			function(int $identifierACK) : void{
				$this->server->getEventListener()->notifyACK($this->internalId, $identifierACK);
			}
		);
	}

	/**
	 * Returns an ID used to identify this session across threads.
	 * @return int
	 */
	public function getInternalId() : int{
		return $this->internalId;
	}

	public function getAddress() : InternetAddress{
		return $this->address;
	}

	public function getID() : int{
		return $this->id;
	}

	public function getState() : int{
		return $this->state;
	}

	public function isTemporal() : bool{
		return $this->isTemporal;
	}

	public function isConnected() : bool{
		return $this->state !== self::STATE_DISCONNECTING and $this->state !== self::STATE_DISCONNECTED;
	}

	public function update(float $time) : void{
		if(!$this->isActive and ($this->lastUpdate + 10) < $time){
			$this->forciblyDisconnect("timeout");

			return;
		}

		if($this->state === self::STATE_DISCONNECTING){
			//by this point we already told the event listener that the session is closing, so we don't need to do it again
			if(!$this->sendLayer->needsUpdate() and !$this->recvLayer->needsUpdate()){
				$this->state = self::STATE_DISCONNECTED;
				$this->logger->debug("Client cleanly disconnected, marking session for destruction");
				return;
			}elseif($this->disconnectionTime + 10 < $time){
				$this->state = self::STATE_DISCONNECTED;
				$this->logger->debug("Timeout during graceful disconnect, forcibly closing session");
				return;
			}
		}

		$this->isActive = false;

		$this->recvLayer->update();
		$this->sendLayer->update();

		if($this->lastPingTime + 5 < $time){
			$this->sendPing();
			$this->lastPingTime = $time;
		}
	}

	private function queueConnectedPacket(Packet $packet, int $reliability, int $orderChannel, bool $immediate = false) : void{
		$packet->encode();

		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = $reliability;
		$encapsulated->orderChannel = $orderChannel;
		$encapsulated->buffer = $packet->getBuffer();

		$this->sendLayer->addEncapsulatedToQueue($encapsulated, $immediate);
	}

	public function addEncapsulatedToQueue(EncapsulatedPacket $packet, bool $immediate) : void{
		$this->sendLayer->addEncapsulatedToQueue($packet, $immediate);
	}

	private function sendPacket(Packet $packet) : void{
		$this->server->sendPacket($packet, $this->address);
	}

	private function sendPing(int $reliability = PacketReliability::UNRELIABLE) : void{
		$pk = new ConnectedPing();
		$pk->sendPingTime = $this->server->getRakNetTimeMS();
		$this->queueConnectedPacket($pk, $reliability, 0, true);
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		if($this->server === null){
			return;
		}

		$id = ord($packet->buffer[0]);
		if($id < MessageIdentifiers::ID_USER_PACKET_ENUM){ //internal data packet
			if($this->state === self::STATE_CONNECTING){
				if($id === ConnectionRequest::$ID){
					$dataPacket = new ConnectionRequest($packet->buffer);
					$dataPacket->decode();

					$pk = new ConnectionRequestAccepted;
					$pk->address = $this->address;
					$pk->sendPingTime = $dataPacket->sendPingTime;
					$pk->sendPongTime = $this->server->getRakNetTimeMS();
					$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0, true);
				}elseif($id === NewIncomingConnection::$ID){
					$dataPacket = new NewIncomingConnection($packet->buffer);
					$dataPacket->decode();

					if($dataPacket->address->port === $this->server->getPort() or !$this->server->portChecking){
						$this->state = self::STATE_CONNECTED; //FINALLY!
						$this->isTemporal = false;
						$this->server->openSession($this);

						//$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime); //can't use this due to system-address count issues in MCPE >.<
						$this->sendPing();
					}
				}
			}elseif($id === DisconnectionNotification::$ID){
				$this->initiateDisconnect("client disconnect");
			}elseif($id === ConnectedPing::$ID){
				$dataPacket = new ConnectedPing($packet->buffer);
				$dataPacket->decode();

				$pk = new ConnectedPong;
				$pk->sendPingTime = $dataPacket->sendPingTime;
				$pk->sendPongTime = $this->server->getRakNetTimeMS();
				$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0);
			}elseif($id === ConnectedPong::$ID){
				$dataPacket = new ConnectedPong($packet->buffer);
				$dataPacket->decode();

				$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime);
			}
		}elseif($this->state === self::STATE_CONNECTED){
			$this->server->getEventListener()->handleEncapsulated($this->internalId, $packet->buffer);
		}else{
			//$this->logger->notice("Received packet before connection: " . bin2hex($packet->buffer));
		}
	}

	/**
	 * @param int $sendPingTime
	 * @param int $sendPongTime TODO: clock differential stuff
	 */
	private function handlePong(int $sendPingTime, int $sendPongTime) : void{
		$this->lastPingMeasure = $this->server->getRakNetTimeMS() - $sendPingTime;
		$this->server->getEventListener()->updatePing($this->internalId, $this->lastPingMeasure);
	}

	public function handlePacket(Packet $packet) : void{
		$this->isActive = true;
		$this->lastUpdate = microtime(true);

		if($packet instanceof Datagram){ //In reality, ALL of these packets are datagrams.
			$this->recvLayer->onDatagram($packet);
		}elseif($packet instanceof ACK){
			$this->sendLayer->onACK($packet);
		}elseif($packet instanceof NACK){
			$this->sendLayer->onNACK($packet);
		}
	}

	/**
	 * Initiates a graceful asynchronous disconnect which ensures both parties got all packets.
	 */
	public function initiateDisconnect(string $reason) : void{
		if($this->isConnected()){
			$this->state = self::STATE_DISCONNECTING;
			$this->disconnectionTime = microtime(true);
			$this->queueConnectedPacket(new DisconnectionNotification(), PacketReliability::RELIABLE_ORDERED, 0, true);
			$this->server->getEventListener()->closeSession($this->internalId, $reason);
			$this->logger->debug("Requesting graceful disconnect because \"$reason\"");
		}
	}

	/**
	 * Disconnects the session with immediate effect, regardless of current session state. Usually used in timeout cases.
	 */
	public function forciblyDisconnect(string $reason) : void{
		$this->state = self::STATE_DISCONNECTED;
		$this->server->getEventListener()->closeSession($this->internalId, $reason);
		$this->logger->debug("Forcibly disconnecting session due to \"$reason\"");
	}

	/**
	 * Returns whether the session is ready to be destroyed (either properly cleaned up or forcibly terminated)
	 */
	public function isFullyDisconnected() : bool{
		return $this->state === self::STATE_DISCONNECTED;
	}
}
