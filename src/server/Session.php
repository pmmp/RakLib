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
use raklib\RakLib;
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

	/** @var SessionManager */
	private $sessionManager;

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

	public function __construct(SessionManager $sessionManager, \Logger $logger, InternetAddress $address, int $clientId, int $mtuSize, int $internalId){
		if($mtuSize < self::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . self::MIN_MTU_SIZE . ", got $mtuSize");
		}
		$this->sessionManager = $sessionManager;
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
				$this->sessionManager->notifyACK($this, $identifierACK);
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
			$this->disconnect("timeout");

			return;
		}

		if($this->state === self::STATE_DISCONNECTING and (
			(!$this->sendLayer->needsUpdate() and !$this->recvLayer->needsUpdate()) or
			$this->disconnectionTime + 10 < $time)
		){
			$this->close();
			return;
		}

		$this->isActive = false;

		$this->recvLayer->update();
		$this->sendLayer->update();

		if($this->lastPingTime + 5 < $time){
			$this->sendPing();
			$this->lastPingTime = $time;
		}
	}

	public function disconnect(string $reason = "unknown") : void{
		$this->sessionManager->removeSession($this, $reason);
	}

	private function queueConnectedPacket(Packet $packet, int $reliability, int $orderChannel, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$packet->encode();

		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = $reliability;
		$encapsulated->orderChannel = $orderChannel;
		$encapsulated->buffer = $packet->getBuffer();

		$this->sendLayer->addEncapsulatedToQueue($encapsulated, $flags);
	}

	public function addEncapsulatedToQueue(EncapsulatedPacket $packet, int $flags) : void{
		$this->sendLayer->addEncapsulatedToQueue($packet, $flags);
	}

	private function sendPacket(Packet $packet) : void{
		$this->sessionManager->sendPacket($packet, $this->address);
	}

	private function sendPing(int $reliability = PacketReliability::UNRELIABLE) : void{
		$pk = new ConnectedPing();
		$pk->sendPingTime = $this->sessionManager->getRakNetTimeMS();
		$this->queueConnectedPacket($pk, $reliability, 0, RakLib::PRIORITY_IMMEDIATE);
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		if($this->sessionManager === null){
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
					$pk->sendPongTime = $this->sessionManager->getRakNetTimeMS();
					$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0, RakLib::PRIORITY_IMMEDIATE);
				}elseif($id === NewIncomingConnection::$ID){
					$dataPacket = new NewIncomingConnection($packet->buffer);
					$dataPacket->decode();

					if($dataPacket->address->port === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
						$this->state = self::STATE_CONNECTED; //FINALLY!
						$this->isTemporal = false;
						$this->sessionManager->openSession($this);

						//$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime); //can't use this due to system-address count issues in MCPE >.<
						$this->sendPing();
					}
				}
			}elseif($id === DisconnectionNotification::$ID){
				//TODO: we're supposed to send an ACK for this, but currently we're just deleting the session straight away
				$this->disconnect("client disconnect");
			}elseif($id === ConnectedPing::$ID){
				$dataPacket = new ConnectedPing($packet->buffer);
				$dataPacket->decode();

				$pk = new ConnectedPong;
				$pk->sendPingTime = $dataPacket->sendPingTime;
				$pk->sendPongTime = $this->sessionManager->getRakNetTimeMS();
				$this->queueConnectedPacket($pk, PacketReliability::UNRELIABLE, 0);
			}elseif($id === ConnectedPong::$ID){
				$dataPacket = new ConnectedPong($packet->buffer);
				$dataPacket->decode();

				$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime);
			}
		}elseif($this->state === self::STATE_CONNECTED){
			$this->sessionManager->streamEncapsulated($this, $packet);
		}else{
			//$this->logger->notice("Received packet before connection: " . bin2hex($packet->buffer));
		}
	}

	/**
	 * @param int $sendPingTime
	 * @param int $sendPongTime TODO: clock differential stuff
	 */
	private function handlePong(int $sendPingTime, int $sendPongTime) : void{
		$this->lastPingMeasure = $this->sessionManager->getRakNetTimeMS() - $sendPingTime;
		$this->sessionManager->streamPingMeasure($this, $this->lastPingMeasure);
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

	public function flagForDisconnection() : void{
		$this->state = self::STATE_DISCONNECTING;
		$this->disconnectionTime = microtime(true);
	}

	public function close() : void{
		if($this->state !== self::STATE_DISCONNECTED){
			$this->state = self::STATE_DISCONNECTED;

			//TODO: the client will send an ACK for this, but we aren't handling it (debug spam)
			$this->queueConnectedPacket(new DisconnectionNotification(), PacketReliability::RELIABLE_ORDERED, 0, RakLib::PRIORITY_IMMEDIATE);

			$this->logger->debug("Closed session");
			$this->sessionManager->removeSessionInternal($this);
		}
	}
}
