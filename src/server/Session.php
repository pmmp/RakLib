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
use function array_fill;
use function assert;
use function count;
use function get_class;
use function microtime;
use function ord;
use function str_split;
use function strlen;
use function time;

class Session{
	public const STATE_CONNECTING = 0;
	public const STATE_CONNECTED = 1;
	public const STATE_DISCONNECTING = 2;
	public const STATE_DISCONNECTED = 3;

	public const MIN_MTU_SIZE = 400;

	/** @var int */
	private $messageIndex = 0;

	/** @var int[] */
	private $sendOrderedIndex;
	/** @var int[] */
	private $sendSequencedIndex;

	/** @var SessionManager */
	private $sessionManager;

	/** @var \Logger */
	private $logger;

	/** @var InternetAddress */
	private $address;

	/** @var int */
	private $state = self::STATE_CONNECTING;
	/** @var int */
	private $mtuSize;
	/** @var int */
	private $id;
	/** @var int */
	private $splitID = 0;

	/** @var int */
	private $sendSeqNumber = 0;

	/** @var float */
	private $lastUpdate;
	/** @var float|null */
	private $disconnectionTime;

	/** @var bool */
	private $isTemporal = true;

	/** @var Datagram[] */
	private $packetToSend = [];
	/** @var bool */
	private $isActive = false;

	/** @var Datagram[] */
	private $recoveryQueue = [];

	/** @var int[][] */
	private $needACK = [];

	/** @var Datagram */
	private $sendQueue;

	/** @var float */
	private $lastPingTime = -1;
	/** @var int */
	private $lastPingMeasure = 1;

	/** @var int */
	private $internalId;

	/** @var ReceiveReliabilityLayer */
	private $recvLayer;

	public function __construct(SessionManager $sessionManager, \Logger $logger, InternetAddress $address, int $clientId, int $mtuSize, int $internalId){
		if($mtuSize < self::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . self::MIN_MTU_SIZE . ", got $mtuSize");
		}
		$this->sessionManager = $sessionManager;
		$this->logger = new \PrefixedLogger($logger, "Session: " . $address->toString());
		$this->address = $address;
		$this->id = $clientId;
		$this->sendQueue = new Datagram();

		$this->lastUpdate = microtime(true);

		$this->sendOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->sendSequencedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->mtuSize = $mtuSize;

		$this->internalId = $internalId;

		$this->recvLayer = new ReceiveReliabilityLayer(
			$this->logger,
			function(EncapsulatedPacket $pk) : void{
				$this->handleEncapsulatedPacketRoute($pk);
			},
			function(AcknowledgePacket $pk) : void{
				$this->sendPacket($pk);
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
			(count($this->sendQueue->packets) === 0 and !$this->recvLayer->needsUpdate() and count($this->packetToSend) === 0 and count($this->recoveryQueue) === 0) or
			$this->disconnectionTime + 10 < $time)
		){
			$this->close();
			return;
		}

		$this->isActive = false;

		$this->recvLayer->update();

		if(count($this->packetToSend) > 0){
			$limit = 16;
			foreach($this->packetToSend as $k => $pk){
				$this->sendDatagram($pk);
				unset($this->packetToSend[$k]);

				if(--$limit <= 0){
					break;
				}
			}

			if(count($this->packetToSend) > ReceiveReliabilityLayer::$WINDOW_SIZE){
				$this->packetToSend = [];
			}
		}

		if(count($this->needACK) > 0){
			foreach($this->needACK as $identifierACK => $indexes){
				if(count($indexes) === 0){
					unset($this->needACK[$identifierACK]);
					$this->sessionManager->notifyACK($this, $identifierACK);
				}
			}
		}


		foreach($this->recoveryQueue as $seq => $pk){
			if($pk->sendTime < (time() - 8)){
				$this->packetToSend[] = $pk;
				unset($this->recoveryQueue[$seq]);
			}else{
				break;
			}
		}

		if($this->lastPingTime + 5 < $time){
			$this->sendPing();
			$this->lastPingTime = $time;
		}

		$this->sendQueue();
	}

	public function disconnect(string $reason = "unknown") : void{
		$this->sessionManager->removeSession($this, $reason);
	}

	private function sendDatagram(Datagram $datagram) : void{
		if($datagram->seqNumber !== null){
			unset($this->recoveryQueue[$datagram->seqNumber]);
		}
		$datagram->seqNumber = $this->sendSeqNumber++;
		$datagram->sendTime = microtime(true);
		$this->recoveryQueue[$datagram->seqNumber] = $datagram;
		$this->sendPacket($datagram);
	}

	private function queueConnectedPacket(Packet $packet, int $reliability, int $orderChannel, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$packet->encode();

		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = $reliability;
		$encapsulated->orderChannel = $orderChannel;
		$encapsulated->buffer = $packet->getBuffer();

		$this->addEncapsulatedToQueue($encapsulated, $flags);
	}

	private function sendPacket(Packet $packet) : void{
		$this->sessionManager->sendPacket($packet, $this->address);
	}

	public function sendQueue() : void{
		if(count($this->sendQueue->packets) > 0){
			$this->sendDatagram($this->sendQueue);
			$this->sendQueue = new Datagram();
		}
	}

	private function sendPing(int $reliability = PacketReliability::UNRELIABLE) : void{
		$pk = new ConnectedPing();
		$pk->sendPingTime = $this->sessionManager->getRakNetTimeMS();
		$this->queueConnectedPacket($pk, $reliability, 0, RakLib::PRIORITY_IMMEDIATE);
	}

	/**
	 * @param EncapsulatedPacket $pk
	 * @param int                $flags
	 */
	private function addToQueue(EncapsulatedPacket $pk, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$priority = $flags & 0b00000111;
		if($pk->needACK and $pk->messageIndex !== null){
			$this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
		}

		$length = $this->sendQueue->length();
		if($length + $pk->getTotalLength() > $this->mtuSize - 36){ //IP header (20 bytes) + UDP header (8 bytes) + RakNet weird (8 bytes) = 36 bytes
			$this->sendQueue();
		}

		if($pk->needACK){
			$this->sendQueue->packets[] = clone $pk;
			$pk->needACK = false;
		}else{
			$this->sendQueue->packets[] = $pk->toBinary();
		}

		if($priority === RakLib::PRIORITY_IMMEDIATE){
			// Forces pending sends to go out now, rather than waiting to the next update interval
			$this->sendQueue();
		}
	}

	/**
	 * @param EncapsulatedPacket $packet
	 * @param int                $flags
	 */
	public function addEncapsulatedToQueue(EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{

		if(($packet->needACK = ($flags & RakLib::FLAG_NEED_ACK) > 0) === true){
			$this->needACK[$packet->identifierACK] = [];
		}

		if(PacketReliability::isOrdered($packet->reliability)){
			$packet->orderIndex = $this->sendOrderedIndex[$packet->orderChannel]++;
		}elseif(PacketReliability::isSequenced($packet->reliability)){
			$packet->orderIndex = $this->sendOrderedIndex[$packet->orderChannel]; //sequenced packets don't increment the ordered channel index
			$packet->sequenceIndex = $this->sendSequencedIndex[$packet->orderChannel]++;
		}

		//IP header size (20 bytes) + UDP header size (8 bytes) + RakNet weird (8 bytes) + datagram header size (4 bytes) + max encapsulated packet header size (20 bytes)
		$maxSize = $this->mtuSize - 60;

		if(strlen($packet->buffer) > $maxSize){
			$buffers = str_split($packet->buffer, $maxSize);
			assert($buffers !== false);
			$bufferCount = count($buffers);

			$splitID = ++$this->splitID % 65536;
			foreach($buffers as $count => $buffer){
				$pk = new EncapsulatedPacket();
				$pk->splitID = $splitID;
				$pk->hasSplit = true;
				$pk->splitCount = $bufferCount;
				$pk->reliability = $packet->reliability;
				$pk->splitIndex = $count;
				$pk->buffer = $buffer;

				if(PacketReliability::isReliable($pk->reliability)){
					$pk->messageIndex = $this->messageIndex++;
				}

				$pk->sequenceIndex = $packet->sequenceIndex;
				$pk->orderChannel = $packet->orderChannel;
				$pk->orderIndex = $packet->orderIndex;

				$this->addToQueue($pk, $flags | RakLib::PRIORITY_IMMEDIATE);
			}
		}else{
			if(PacketReliability::isReliable($packet->reliability)){
				$packet->messageIndex = $this->messageIndex++;
			}
			$this->addToQueue($packet, $flags);
		}
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
		}else{
			if($packet instanceof ACK){
				$packet->decode();
				foreach($packet->packets as $seq){
					if(isset($this->recoveryQueue[$seq])){
						foreach($this->recoveryQueue[$seq]->packets as $pk){
							if($pk instanceof EncapsulatedPacket and $pk->needACK and $pk->messageIndex !== null){
								unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
							}
						}
						unset($this->recoveryQueue[$seq]);
					}
				}
			}elseif($packet instanceof NACK){
				$packet->decode();
				foreach($packet->packets as $seq){
					if(isset($this->recoveryQueue[$seq])){
						$this->packetToSend[] = $this->recoveryQueue[$seq];
						unset($this->recoveryQueue[$seq]);
					}
				}
			}
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
