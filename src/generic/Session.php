<?php

/*
 * This file is part of RakLib.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/RakLib>
 *
 * RakLib is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * RakLib is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace raklib\generic;

use raklib\protocol\ACK;
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\ConnectedPacket;
use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectedPong;
use raklib\protocol\Datagram;
use raklib\protocol\DisconnectionNotification;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NACK;
use raklib\protocol\Packet;
use raklib\protocol\PacketReliability;
use raklib\protocol\PacketSerializer;
use raklib\utils\InternetAddress;
use function hrtime;
use function intdiv;
use function microtime;
use function ord;

abstract class Session{
	public const MAX_SPLIT_PART_COUNT = 128;
	public const MAX_CONCURRENT_SPLIT_COUNT = 4;

	public const STATE_CONNECTING = 0;
	public const STATE_CONNECTED = 1;
	public const STATE_DISCONNECT_PENDING = 2;
	public const STATE_DISCONNECT_NOTIFIED = 3;
	public const STATE_DISCONNECTED = 4;

	public const MIN_MTU_SIZE = 400;

	private \Logger $logger;

	protected InternetAddress $address;

	protected int $state = self::STATE_CONNECTING;

	private int $id;

	private float $lastUpdate;
	private float $disconnectionTime = 0;

	private bool $isActive = false;

	private float $lastPingTime = -1;

	private int $lastPingMeasure = 1;

	private ReceiveReliabilityLayer $recvLayer;

	private SendReliabilityLayer $sendLayer;

	public function __construct(\Logger $logger, InternetAddress $address, int $clientId, int $mtuSize){
		if($mtuSize < self::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . self::MIN_MTU_SIZE . ", got $mtuSize");
		}
		$this->logger = new \PrefixedLogger($logger, "Session: " . $address->toString());
		$this->address = $address;
		$this->id = $clientId;

		$this->lastUpdate = microtime(true);

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
				$this->onPacketAck($identifierACK);
			}
		);
	}

	/**
	 * Sends a packet in the appropriate way for the session type.
	 */
	abstract protected function sendPacket(Packet $packet) : void;

	/**
	 * Called when a packet for which an ACK was requested is ACKed.
	 */
	abstract protected function onPacketAck(int $identifierACK) : void;

	/**
	 * Called when the session is terminated for any reason.
	 *
	 * @param int $reason one of the DisconnectReason::* constants
	 * @phpstan-param DisconnectReason::* $reason
	 *
	 * @see DisconnectReason
	 */
	abstract protected function onDisconnect(int $reason) : void;

	/**
	 * Called when a packet is received while the session is in the "connecting" state. This should only handle RakNet
	 * connection packets. Any other packets should be ignored.
	 */
	abstract protected function handleRakNetConnectionPacket(string $packet) : void;

	/**
	 * Called when a user packet (ID >= ID_USER_PACKET_ENUM) is received from the remote peer.
	 *
	 * @see MessageIdentifiers::ID_USER_PACKET_ENUM
	 */
	abstract protected function onPacketReceive(string $packet) : void;

	/**
	 * Called when a new ping measurement is recorded.
	 */
	abstract protected function onPingMeasure(int $pingMS) : void;

	/**
	 * Returns a monotonically increasing timestamp. It does not need to match UNIX time.
	 * This is used to calculate ping.
	 */
	protected function getRakNetTimeMS() : int{
		return intdiv(hrtime(true), 1_000_000);
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

	public function isTemporary() : bool{
		return $this->state === self::STATE_CONNECTING;
	}

	public function isConnected() : bool{
		return
			$this->state !== self::STATE_DISCONNECT_PENDING and
			$this->state !== self::STATE_DISCONNECT_NOTIFIED and
			$this->state !== self::STATE_DISCONNECTED;
	}

	public function update(float $time) : void{
		if(!$this->isActive and ($this->lastUpdate + 10) < $time){
			$this->forciblyDisconnect(DisconnectReason::PEER_TIMEOUT);

			return;
		}

		if($this->state === self::STATE_DISCONNECT_PENDING || $this->state === self::STATE_DISCONNECT_NOTIFIED){
			//by this point we already told the event listener that the session is closing, so we don't need to do it again
			if(!$this->sendLayer->needsUpdate() and !$this->recvLayer->needsUpdate()){
				if($this->state === self::STATE_DISCONNECT_PENDING){
					$this->queueConnectedPacket(new DisconnectionNotification(), PacketReliability::RELIABLE_ORDERED, 0, true);
					$this->state = self::STATE_DISCONNECT_NOTIFIED;
					$this->logger->debug("All pending traffic flushed, sent disconnect notification");
				}else{
					$this->state = self::STATE_DISCONNECTED;
					$this->logger->debug("Client cleanly disconnected, marking session for destruction");
					return;
				}
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

	protected function queueConnectedPacket(ConnectedPacket $packet, int $reliability, int $orderChannel, bool $immediate = false) : void{
		$out = new PacketSerializer();  //TODO: reuse streams to reduce allocations
		$packet->encode($out);

		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = $reliability;
		$encapsulated->orderChannel = $orderChannel;
		$encapsulated->buffer = $out->getBuffer();

		$this->sendLayer->addEncapsulatedToQueue($encapsulated, $immediate);
	}

	public function addEncapsulatedToQueue(EncapsulatedPacket $packet, bool $immediate) : void{
		$this->sendLayer->addEncapsulatedToQueue($packet, $immediate);
	}

	protected function sendPing(int $reliability = PacketReliability::UNRELIABLE) : void{
		$this->queueConnectedPacket(ConnectedPing::create($this->getRakNetTimeMS()), $reliability, 0, true);
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		$id = ord($packet->buffer[0]);
		if($id < MessageIdentifiers::ID_USER_PACKET_ENUM){ //internal data packet
			if($this->state === self::STATE_CONNECTING){
				$this->handleRakNetConnectionPacket($packet->buffer);
			}elseif($id === MessageIdentifiers::ID_DISCONNECTION_NOTIFICATION){
				$this->handleRemoteDisconnect();
			}elseif($id === MessageIdentifiers::ID_CONNECTED_PING){
				$dataPacket = new ConnectedPing();
				$dataPacket->decode(new PacketSerializer($packet->buffer));
				$this->queueConnectedPacket(ConnectedPong::create(
					$dataPacket->sendPingTime,
					$this->getRakNetTimeMS()
				), PacketReliability::UNRELIABLE, 0);
			}elseif($id === MessageIdentifiers::ID_CONNECTED_PONG){
				$dataPacket = new ConnectedPong();
				$dataPacket->decode(new PacketSerializer($packet->buffer));

				$this->handlePong($dataPacket->sendPingTime, $dataPacket->sendPongTime);
			}
		}elseif($this->state === self::STATE_CONNECTED){
			$this->onPacketReceive($packet->buffer);
		}else{
			//$this->logger->notice("Received packet before connection: " . bin2hex($packet->buffer));
		}
	}

	/**
	 * @param int $sendPongTime TODO: clock differential stuff
	 */
	private function handlePong(int $sendPingTime, int $sendPongTime) : void{
		$currentTime = $this->getRakNetTimeMS();
		if($currentTime < $sendPingTime){
			$this->logger->debug("Received invalid pong: timestamp is in the future by " . ($sendPingTime - $currentTime) . " ms");
		}else{
			$this->lastPingMeasure = $currentTime - $sendPingTime;
			$this->onPingMeasure($this->lastPingMeasure);
		}
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
	 *
	 * @param int $reason one of the DisconnectReason constants
	 * @phpstan-param DisconnectReason::* $reason
	 *
	 * @see DisconnectReason
	 */
	public function initiateDisconnect(int $reason) : void{
		if($this->isConnected()){
			$this->state = self::STATE_DISCONNECT_PENDING;
			$this->disconnectionTime = microtime(true);
			$this->onDisconnect($reason);
			$this->logger->debug("Requesting graceful disconnect because \"" . DisconnectReason::toString($reason) . "\"");
		}
	}

	/**
	 * Disconnects the session with immediate effect, regardless of current session state. Usually used in timeout cases.
	 *
	 * @param int $reason one of the DisconnectReason constants
	 * @phpstan-param DisconnectReason::* $reason
	 *
	 * @see DisconnectReason
	 */
	public function forciblyDisconnect(int $reason) : void{
		$this->state = self::STATE_DISCONNECTED;
		$this->onDisconnect($reason);
		$this->logger->debug("Forcibly disconnecting session due to " . DisconnectReason::toString($reason));
	}

	private function handleRemoteDisconnect() : void{
		//the client will expect an ACK for this; make sure it gets sent, because after forcible termination
		//there won't be any session ticks to update it
		$this->recvLayer->update();

		if($this->isConnected()){
			//the client might have disconnected after the server sent a disconnect notification, but before the client
			//received it - in this case, we don't want to notify the event handler twice
			$this->onDisconnect(DisconnectReason::CLIENT_DISCONNECT);
		}
		$this->state = self::STATE_DISCONNECTED;
		$this->logger->debug("Terminating session due to client disconnect");
	}

	/**
	 * Returns whether the session is ready to be destroyed (either properly cleaned up or forcibly terminated)
	 */
	public function isFullyDisconnected() : bool{
		return $this->state === self::STATE_DISCONNECTED;
	}
}
