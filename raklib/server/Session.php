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

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_DISCONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;

class Session{
	const STATE_UNCONNECTED = 0;
	const STATE_CONNECTING_1 = 1;
	const STATE_CONNECTING_2 = 2;
	const STATE_CONNECTED = 3;

	public static $WINDOW_SIZE = 1024;

	/** @var SessionManager */
	protected $sessionManager;
	protected $address;
	protected $port;
	protected $state = self::STATE_UNCONNECTED;
	protected $mtuSize = 548; //Min size
	protected $id = 0;

	protected $lastSeqNumber = 0;
	protected $sendSeqNumber = 0;

	protected $timeout;

	protected $lastUpdate;

	protected $isActive;

	/** @var int[] */
	protected $ACKQueue = [];
	/** @var int[] */
	protected $NACKQueue = [];

	/** @var DataPacket[] */
	protected $recoveryQueue = [];

	/** @var DataPacket */
	protected $sendQueue;

	public function __construct(SessionManager $sessionManager, $address, $port){
		$this->sessionManager = $sessionManager;
		$this->address = $address;
		$this->port = $port;
		$this->sendQueue = new DATA_PACKET_4();
		$this->lastUpdate = microtime(true);
		$this->isActive = false;
	}

	public function getAddress(){
		return $this->address;
	}

	public function getPort(){
		return $this->port;
	}

	public function getID(){
		return $this->id;
	}

	public function update($time){
		if(!$this->isActive and ($this->lastUpdate + 10) < $time){
			$this->disconnect();
			return;
		}else{
			$this->lastUpdate = $time;
		}
		$this->isActive = true;

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

		$this->sendQueue();
	}

	public function disconnect(){
		$this->sessionManager->removeSession($this);
	}

	public function needUpdate(){
		return count($this->ACKQueue) > 0 or count($this->NACKQueue) > 0 or count($this->sendQueue->packets) > 0;
	}

	protected function sendPacket(Packet $packet){
		$this->sessionManager->sendPacket($packet, $this->address, $this->port);
	}

	protected function sendQueue(){
		if(count($this->sendQueue->packets) > 0){
			$this->sendQueue->seqNumber = $this->sendSeqNumber++;
			$this->sendPacket($this->sendQueue);
			$this->recoveryQueue[$this->sendQueue->seqNumber] = $this->sendQueue;
			$this->sendQueue = new DATA_PACKET_4();
		}
	}

	/**
	 * @param EncapsulatedPacket|string $pk
	 */
	protected function addToQueue($pk){
		$length = $this->sendQueue->length();
		if($length + $pk->getTotalLength() > $this->mtuSize){
			$this->sendQueue();
		}
		$this->sendQueue->packets[] = $pk;
	}

	public function addEncapsulatedToQueue(EncapsulatedPacket $packet){
		//$packet->reliability =
	}

	protected function handleEncapsulatedPacket(EncapsulatedPacket $packet){
		$id = ord($packet->buffer{0});
		if($id < 0x80){ //internal data packet
			if($this->state === self::STATE_CONNECTING_2){
				if($id === CLIENT_CONNECT_DataPacket::$ID){
					$dataPacket = new CLIENT_CONNECT_DataPacket;
					$dataPacket->buffer = $packet->buffer;
					$dataPacket->decode();
					$pk = new SERVER_HANDSHAKE_DataPacket;
					$pk->port = $this->port;
					$pk->session = $dataPacket->session;
					$pk->session2 = Binary::readLong("\x00\x00\x00\x00\x04\x44\x0b\xa9");
					$pk->encode();

					$sendPacket = new EncapsulatedPacket();
					$sendPacket->reliability = 0;
					$sendPacket->buffer = $pk->buffer;
					$this->addToQueue($sendPacket);
					$this->sendQueue();
				}elseif($id === CLIENT_HANDSHAKE_DataPacket::$ID){
					$dataPacket = new CLIENT_HANDSHAKE_DataPacket;
					$dataPacket->buffer = $packet->buffer;
					$dataPacket->decode();

					if($dataPacket->port === $this->sessionManager->getPort()){
						$this->state = self::STATE_CONNECTED; //FINALLY!
					}
				}
			}elseif($id === CLIENT_DISCONNECT_DataPacket::$ID){
				$this->disconnect(); //TODO: reasons
			}//TODO: add PING/PONG (0x00/0x03) automatic latency measure
		}elseif($this->state === self::STATE_CONNECTED){

			//TODO: split packet handling
			//TODO: packet reordering
			//TODO: stream channels
		}
	}

	public function handlePacket(Packet $packet){
		$this->isActive = true;
		if($this->state === self::STATE_CONNECTED or $this->state === self::STATE_CONNECTING_2){
			if($packet::$ID >= 0x80 and $packet::$ID <= 0x8f and $packet instanceof DataPacket){ //Data packet
				$packet->decode();
				$diff = $packet->seqNumber - $this->lastSeqNumber;

				if($diff > static::$WINDOW_SIZE){
					return;
				}elseif($diff > 1){
					for($i = $this->lastSeqNumber + 1; $i < $packet->seqNumber; ++$i){
						$this->NACKQueue[$i] = $i;
					}
				}else{
					if($diff < 0){
						unset($this->NACKQueue[$packet->seqNumber]);
					}else{
						$this->lastSeqNumber = $packet->seqNumber;
					}
					$this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;
				}

				foreach($packet->packets as $pk){
					$this->handleEncapsulatedPacket($pk);
				}
			}else{
				if($packet instanceof ACK){
					foreach($packet->packets as $seq){
						unset($this->recoveryQueue[$seq]);
					}
				}elseif($packet instanceof NACK){
					foreach($packet->packets as $seq){
						if(isset($this->recoveryQueue[$seq])){
							$this->sendPacket($this->recoveryQueue[$seq]);
						}
					}
				}
			}

		}elseif($packet::$ID > 0x00 and $packet::$ID < 0x80){ //Not Data packet :)
			$packet->decode();
			if($packet instanceof UNCONNECTED_PING){
				$pk = new UNCONNECTED_PONG();
				$pk->serverID = $this->sessionManager->getID();
				$pk->pingID = $packet->pingID;
				$pk->serverName = $this->sessionManager->getName();
				$this->sendPacket($pk);
			}elseif($packet instanceof OPEN_CONNECTION_REQUEST_1){
				$packet->protocol; //TODO: check protocol number and refuse connections
				$pk = new OPEN_CONNECTION_REPLY_1;
				$pk->mtuSize = $packet->mtuSize;
				$pk->serverID = $this->sessionManager->getID();
				$this->sendPacket($pk);
				$this->state = self::STATE_CONNECTING_1;
			}elseif($this->state === self::STATE_CONNECTING_1 and $packet instanceof OPEN_CONNECTION_REQUEST_2){
				$this->id = $packet->clientID;
				if($packet->serverPort === $this->sessionManager->getPort()){
					$this->mtuSize = min($packet->mtuSize, 1464); //Max size, do not allow creating large buffers to fill server memory
					$pk = new OPEN_CONNECTION_REPLY_2;
					$pk->mtuSize = $this->mtuSize;
					$pk->serverID = $this->sessionManager->getID();
					$pk->clientPort = $this->port;
					$this->sendPacket($pk);
					$this->state = self::STATE_CONNECTING_2;
				}
			}
		}
	}
}