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

	public function sendEncapsulated($identifier, EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($identifier)) . $identifier . chr($flags) . $packet->toBinary(true);
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
		$this->server->join();
	}

	public function emergencyShutdown(){
		@socket_write($this->socket, "\x00\x00\x00\x01\x7f"); //RakLib::PACKET_EMERGENCY_SHUTDOWN
		$this->server->join();
	}

	protected function invalidSession($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer);
	}

	protected function socketRead($len){
		$buffer = "";
		while(strlen($buffer) < $len){
			$buffer .= @socket_read($this->socket, $len - strlen($buffer));
		}

		return $buffer;
	}

	/**
	 * @return bool
	 */
	public function handlePacket(){
		if(($len = @socket_read($this->socket, 4)) !== false){
			if(strlen($len) < 4){
				$len .= $this->socketRead(4 - strlen($len));
			}
			$packet = $this->socketRead(Binary::readInt($len));
			$id = ord($packet{0});
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$flags = ord($packet{$offset++});
				$buffer = substr($packet, $offset);
				$this->instance->handleEncapsulated($identifier, EncapsulatedPacket::fromBinary($buffer, true), $flags);
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
			}elseif($id === RakLib::PACKET_ACK_NOTIFICATION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$identifierACK = Binary::readInt(substr($packet, $offset, 4));
				$this->instance->notifyACK($identifier, $identifierACK);
			}
			return true;
		}

		return false;
	}
}