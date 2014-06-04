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
		while(true){
			$this->receivePacket();
			//$this->sendPacket();
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
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . Binary::writeInt($packet->toBinary());
		@socket_write($this->internalSocket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	public function receiveStream(){
		if(($len = @socket_read($this->internalSocket, 4)) !== ""){
			$packet = socket_read($this->internalSocket, Binary::readInt($len));
			$id = ord($packet{0});
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{1});
				$identifier = substr($packet, 2, $len);
				if(isset($this->sessions[$identifier])){
					$buffer = substr($packet, 2 + $len);
				}else{
					//TODO: reply with close notification
				}
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

	public function removeSession(Session $session){
		unset($this->sessions[$session->getAddress() . ":" . $session->getPort()]);
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