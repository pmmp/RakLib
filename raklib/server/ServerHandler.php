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
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;

class ServerHandler{

	/** @var RakLibServer */
	protected $server;
	/** @var ServerInstance */
	protected $instance;
	protected $socket;

	public function __construct(RakLibServer $server, ServerInstance $instance){
		$this->server = $server;
		$this->instance = $instance;
		$this->socket = $this->server->getExternalSocket();
	}

	public function sendEncapsulated($identifier, EncapsulatedPacket $packet){
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($identifier)) . $identifier . $packet->toBinary();
		@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	public function closeSession($identifier, $reason){
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	public function shutdown(){
		$buffer = chr(RakLib::PACKET_SHUTDOWN);
		@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer);
		@socket_close($this->socket);
	}

	public function emergencyShutdown(){
		@socket_write($this->socket, "\x00\x00\x00\x01\x7f"); //RakLib::PACKET_EMERGENCY_SHUTDOWN
	}

	protected function invalidSession($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	public function handlePacket(){
		if(($len = @socket_read($this->socket, 4)) !== ""){
			$packet = socket_read($this->socket, Binary::readInt($len));
			$id = ord($packet{0});
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$buffer = substr($packet, $offset);
				$this->instance->handleEncapsulated($identifier, EncapsulatedPacket::fromBinary($buffer));
			}elseif($id === RakLib::PACKET_OPEN_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2), false);
				$offset += 2;
				$clientID = Binary::readLong(substr($packet, $offset, 8));
				$this->instance->openSession($identifier, $address, $port, $clientID);
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$len = ord($packet{$offset++});
				$reason = substr($packet, $offset, $len);
				$this->instance->closeSession($identifier, $reason);
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$this->instance->closeSession($identifier, "Invalid session");
			}
		}
	}
}