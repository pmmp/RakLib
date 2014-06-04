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
use raklib\protocol\ADVERTISE_SYSTEM;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_1;
use raklib\protocol\DATA_PACKET_2;
use raklib\protocol\DATA_PACKET_3;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DATA_PACKET_5;
use raklib\protocol\DATA_PACKET_6;
use raklib\protocol\DATA_PACKET_7;
use raklib\protocol\DATA_PACKET_8;
use raklib\protocol\DATA_PACKET_9;
use raklib\protocol\DATA_PACKET_A;
use raklib\protocol\DATA_PACKET_B;
use raklib\protocol\DATA_PACKET_C;
use raklib\protocol\DATA_PACKET_D;
use raklib\protocol\DATA_PACKET_E;
use raklib\protocol\DATA_PACKET_F;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\protocol\Packet;
use raklib\RakLib;

class SessionManager{
	/** @var Packet */
	protected $packetPool;
	/** @var RakLibServer */
	protected $server;

	protected $socket;

	protected $internalSocket;

	protected $receiveBytes = 0;
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessions = [];

	protected $shutdown = false;

	public function __construct(RakLibServer $server, UDPServerSocket $socket){
		$this->server = $server;
		$this->socket = $socket;
		$this->registerPackets();
		$this->internalSocket = $this->server->getInternalSocket();
		$this->run();
	}

	public function getPort(){
		return $this->server->getPort();
	}

	public function getLogger(){
		return $this->server->getLogger();
	}

	public function run(){
		$ticks = 0;
		while($this->shutdown !== true){
			$this->receivePacket();
			$this->receiveStream();
			//TODO: add different Windows / Linux usleep()
			++$ticks;
			if($ticks % 20 === 0){
				$time = microtime(true);
				foreach($this->sessions as $session){
					if($session->needUpdate()){
						$session->update($time);
					}
				}
			}
		}

	}

	private function receivePacket(){
		$buffer = $source = $port = null;
		if(($len = $this->socket->readPacket($buffer, $source, $port)) > 0){
			$this->receiveBytes += $len;
			$pid = ord($buffer{0});
			if(isset($this->packetPool[$pid])){
				$packet = clone $this->packetPool[$pid];
				$packet->buffer = $buffer;
				$this->getSession($source, $port)->handlePacket($packet);
			} //TODO: handle unknown packets
		}
	}

	public function sendPacket(Packet $packet, $dest, $port){
		$packet->encode();
		$this->sendBytes += $this->socket->writePacket($packet->buffer, $dest, $port);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet){
		$id = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . $packet->toBinary();
		@socket_write($this->internalSocket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	protected function streamClose($identifier, $reason){
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		@socket_write($this->internalSocket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	protected function streamInvalid($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		@socket_write($this->internalSocket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	protected function streamOpen(Session $session){
		$identifier = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
		@socket_write($this->internalSocket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	protected function socketRead($len){
		$buffer = "";
		while(strlen($buffer) < $len){
			$buffer .= @socket_read($this->internalSocket, $len - strlen($buffer));
		}

		return $buffer;
	}

	public function receiveStream(){
		if(($len = @socket_read($this->internalSocket, 4)) !== ""){
			if(strlen($len) < 4){
				$len .= $this->socketRead(4 - strlen($len));
			}
			$packet = $this->socketRead(Binary::readInt($len));
			$id = ord($packet{0});
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$buffer = substr($packet, 2 + $len);
					$this->sessions[$identifier]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer));
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === RakLib::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}
				@socket_close($this->internalSocket);
				$this->socket->close();
				$this->shutdown = true;
			}elseif($id === RakLib::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}
		}
	}

	/**
	 * @param string $ip
	 * @param int    $port
	 *
	 * @return Session
	 */
	public function getSession($ip, $port){
		$id = $ip . ":" . $port;
		if(!isset($this->sessions[$id])){
			$this->sessions[$id] = new Session($this, $ip, $port);
		}
		return $this->sessions[$id];
	}

	public function removeSession(Session $session, $reason = "unknown"){
		$id = $session->getAddress() . ":" . $session->getPort();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary("\x00\x00\x08\x15")); //CLIENT_DISCONNECT packet 0x15
			$this->sessions[$id]->sendQueue();
			unset($this->sessions[$id]);
			$this->streamClose($id, $reason);
		}
	}

	public function openSession(Session $session){
		$this->streamOpen($session);
	}

	public function getName(){
		return "MCCPP;Demo;TEST"; //TODO
	}

	public function getID(){
		return 0; //TODO
	}

	private function registerPackets(){
		$this->packetPool[UNCONNECTED_PING::$ID] = new UNCONNECTED_PING;
		$this->packetPool[UNCONNECTED_PING_OPEN_CONNECTIONS::$ID] = new UNCONNECTED_PING_OPEN_CONNECTIONS;
		$this->packetPool[OPEN_CONNECTION_REQUEST_1::$ID] = new OPEN_CONNECTION_REQUEST_1;
		$this->packetPool[OPEN_CONNECTION_REPLY_1::$ID] = new OPEN_CONNECTION_REPLY_1;
		$this->packetPool[OPEN_CONNECTION_REQUEST_2::$ID] = new OPEN_CONNECTION_REQUEST_2;
		$this->packetPool[OPEN_CONNECTION_REPLY_2::$ID] = new OPEN_CONNECTION_REPLY_2;
		$this->packetPool[UNCONNECTED_PONG::$ID] = new UNCONNECTED_PONG;
		$this->packetPool[ADVERTISE_SYSTEM::$ID] = new ADVERTISE_SYSTEM;
		$this->packetPool[DATA_PACKET_0::$ID] = new DATA_PACKET_0;
		$this->packetPool[DATA_PACKET_1::$ID] = new DATA_PACKET_1;
		$this->packetPool[DATA_PACKET_2::$ID] = new DATA_PACKET_2;
		$this->packetPool[DATA_PACKET_3::$ID] = new DATA_PACKET_3;
		$this->packetPool[DATA_PACKET_4::$ID] = new DATA_PACKET_4;
		$this->packetPool[DATA_PACKET_5::$ID] = new DATA_PACKET_5;
		$this->packetPool[DATA_PACKET_6::$ID] = new DATA_PACKET_6;
		$this->packetPool[DATA_PACKET_7::$ID] = new DATA_PACKET_7;
		$this->packetPool[DATA_PACKET_8::$ID] = new DATA_PACKET_8;
		$this->packetPool[DATA_PACKET_9::$ID] = new DATA_PACKET_9;
		$this->packetPool[DATA_PACKET_A::$ID] = new DATA_PACKET_A;
		$this->packetPool[DATA_PACKET_B::$ID] = new DATA_PACKET_B;
		$this->packetPool[DATA_PACKET_C::$ID] = new DATA_PACKET_C;
		$this->packetPool[DATA_PACKET_D::$ID] = new DATA_PACKET_D;
		$this->packetPool[DATA_PACKET_E::$ID] = new DATA_PACKET_E;
		$this->packetPool[DATA_PACKET_F::$ID] = new DATA_PACKET_F;
		$this->packetPool[NACK::$ID] = new NACK;
		$this->packetPool[ACK::$ID] = new ACK;
	}
}