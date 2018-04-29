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

use raklib\protocol\ACK;
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

class Session{
	const STATE_CONNECTING = 0;
	const STATE_CONNECTED = 1;
	const STATE_DISCONNECTING = 2;
	const STATE_DISCONNECTED = 3;

	const MAX_SPLIT_SIZE = 128;
	const MAX_SPLIT_COUNT = 4;

	public static $WINDOW_SIZE = 2048;

	/** @var int */
	private $messageIndex = 0;
	/** @var int[] */
	private $channelIndex;

	/** @var SessionManager */
	private $sessionManager;

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

	/** @var int[] */
	private $ACKQueue = [];
	/** @var int[] */
	private $NACKQueue = [];

	/** @var Datagram[] */
	private $recoveryQueue = [];

	/** @var Datagram[][] */
	private $splitPackets = [];

	/** @var int[][] */
	private $needACK = [];

	/** @var Datagram */
	private $sendQueue;

	/** @var int */
	private $windowStart;
	/** @var int */
	private $windowEnd;
	/** @var int */
	private $highestSeqNumberThisTick = -1;

	/** @var int */
	private $reliableWindowStart;
	/** @var int */
	private $reliableWindowEnd;
	/** @var EncapsulatedPacket[] */
	private $reliableWindow = [];

	/** @var float */
	private $lastPingTime = -1;
	/** @var int */
	private $lastPingMeasure = 1;

	public function __construct(SessionManager $sessionManager, InternetAddress $address, int $clientId, int $mtuSize){
		$this->sessionManager = $sessionManager;
		$this->address = $address;
		$this->id = $clientId;
		$this->sendQueue = new Datagram();
		$this->sendQueue->headerFlags = Datagram::BITFLAG_NEEDS_B_AND_AS;

		$this->lastUpdate = microtime(true);
		$this->windowStart = 0;
		$this->windowEnd = self::$WINDOW_SIZE;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = self::$WINDOW_SIZE;

		$this->channelIndex = array_fill(0, 32, 0);

		$this->mtuSize = $mtuSize;
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
			(empty($this->ACKQueue) and empty($this->NACKQueue) and empty($this->packetToSend) and empty($this->recoveryQueue)) or
			$this->disconnectionTime + 10 < $time)
		){
			$this->close();
			return;
		}

		$this->isActive = false;

		$diff = $this->highestSeqNumberThisTick - $this->windowStart + 1;
		assert($diff >= 0);
		if($diff > 0){
			//Move the receive window to account for packets we either received or are about to NACK
			//we ignore any sequence numbers that we sent NACKs for, because we expect the client to resend them
			//when it gets a NACK for it

			$this->windowStart += $diff;
			$this->windowEnd += $diff;
		}

		if(count($this->ACKQueue) > 0){
			$pk = new ACK();
			$pk->packets = $this->ACKQueue;
			$this->sendPacket($pk);
			$this->ACKQueue = [];
		}

		if(count($this->NACKQueue) > 0){
			$pk = new NACK();
			$pk->packets = $this->NACKQueue;
			$this->sendPacket($pk);
			$this->NACKQueue = [];
		}

		if(count($this->packetToSend) > 0){
			$limit = 16;
			foreach($this->packetToSend as $k => $pk){
				$this->sendDatagram($pk);
				unset($this->packetToSend[$k]);

				if(--$limit <= 0){
					break;
				}
			}

			if(count($this->packetToSend) > self::$WINDOW_SIZE){
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
		$encapsulated->buffer = $packet->buffer;

		$this->addEncapsulatedToQueue($encapsulated, $flags);
	}

	private function sendPacket(Packet $packet) : void{
		$this->sessionManager->sendPacket($packet, $this->address);
	}

	public function sendQueue() : void{
		if(count($this->sendQueue->packets) > 0){
			$this->sendDatagram($this->sendQueue);
			$this->sendQueue = new Datagram();
			$this->sendQueue->headerFlags = Datagram::BITFLAG_NEEDS_B_AND_AS;
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

		if(PacketReliability::isSequencedOrOrdered($packet->reliability)){
			$packet->orderIndex = $this->channelIndex[$packet->orderChannel]++;
		}

		//IP header size (20 bytes) + UDP header size (8 bytes) + RakNet weird (8 bytes) + datagram header size (4 bytes) + max encapsulated packet header size (20 bytes)
		$maxSize = $this->mtuSize - 60;

		if(strlen($packet->buffer) > $maxSize){
			$buffers = str_split($packet->buffer, $maxSize);
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

	private function handleSplit(EncapsulatedPacket $packet) : void{
		if($packet->splitCount >= self::MAX_SPLIT_SIZE or $packet->splitIndex >= self::MAX_SPLIT_SIZE or $packet->splitIndex < 0){
			return;
		}


		if(!isset($this->splitPackets[$packet->splitID])){
			if(count($this->splitPackets) >= self::MAX_SPLIT_COUNT){
				return;
			}
			$this->splitPackets[$packet->splitID] = [$packet->splitIndex => $packet];
		}else{
			$this->splitPackets[$packet->splitID][$packet->splitIndex] = $packet;
		}

		if(count($this->splitPackets[$packet->splitID]) === $packet->splitCount){
			$pk = new EncapsulatedPacket();
			$pk->buffer = "";
			for($i = 0; $i < $packet->splitCount; ++$i){
				$pk->buffer .= $this->splitPackets[$packet->splitID][$i]->buffer;
			}

			$pk->length = strlen($pk->buffer);
			unset($this->splitPackets[$packet->splitID]);

			$this->handleEncapsulatedPacketRoute($pk);
		}
	}

	private function handleEncapsulatedPacket(EncapsulatedPacket $packet) : void{
		if($packet->messageIndex === null){
			$this->handleEncapsulatedPacketRoute($packet);
		}else{
			if($packet->messageIndex < $this->reliableWindowStart or $packet->messageIndex > $this->reliableWindowEnd){
				return;
			}

			if($packet->messageIndex === $this->reliableWindowStart){
				$this->reliableWindowStart++;
				$this->reliableWindowEnd++;
				$this->handleEncapsulatedPacketRoute($packet);

				if(count($this->reliableWindow) > 0){
					ksort($this->reliableWindow);

					foreach($this->reliableWindow as $index => $pk){
						if($index !== $this->reliableWindowStart){
							break;
						}
						$this->reliableWindowStart++;
						$this->reliableWindowEnd++;
						$this->handleEncapsulatedPacketRoute($pk);
						unset($this->reliableWindow[$index]);
					}
				}
			}else{
				$this->reliableWindow[$packet->messageIndex] = $packet;
			}
		}

	}


	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		if($this->sessionManager === null){
			return;
		}

		if($packet->hasSplit){
			if($this->state === self::STATE_CONNECTED){
				$this->handleSplit($packet);
			}

			return;
		}

		$id = ord($packet->buffer{0});
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

			//TODO: stream channels
		}else{
			//$this->sessionManager->getLogger()->notice("Received packet before connection: " . bin2hex($packet->buffer));
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
			$packet->decode();

			if($packet->seqNumber < $this->windowStart or $packet->seqNumber > $this->windowEnd or isset($this->ACKQueue[$packet->seqNumber])){
				$this->sessionManager->getLogger()->debug("Received duplicate or out-of-window packet from " . $this->address . " (sequence number $packet->seqNumber, window " . $this->windowStart . "-" . $this->windowEnd . ")");
				return;
			}

			unset($this->NACKQueue[$packet->seqNumber]);
			$this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;
			if($this->highestSeqNumberThisTick < $packet->seqNumber){
				$this->highestSeqNumberThisTick = $packet->seqNumber;
			}

			if($packet->seqNumber === $this->windowStart){
				//got a contiguous packet, shift the receive window
				//this packet might complete a sequence of out-of-order packets, so we incrementally check the indexes
				//to see how far to shift the window, and stop as soon as we either find a gap or have an empty window
				for(; isset($this->ACKQueue[$this->windowStart]); ++$this->windowStart){
					++$this->windowEnd;
				}
			}elseif($packet->seqNumber > $this->windowStart){
				//we got a gap - a later packet arrived before earlier ones did
				//we add the earlier ones to the NACK queue
				//if the missing packets arrive before the end of tick, they'll be removed from the NACK queue
				for($i = $this->windowStart; $i < $packet->seqNumber; ++$i){
					if(!isset($this->ACKQueue[$i])){
						$this->NACKQueue[$i] = $i;
					}
				}
			}else{
				assert(false, "received packet before window start");
			}

			foreach($packet->packets as $pk){
				$this->handleEncapsulatedPacket($pk);
			}
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

			$this->sessionManager->getLogger()->debug("Closed session for $this->address");
			$this->sessionManager->removeSessionInternal($this);
			$this->sessionManager = null;
		}
	}
}
