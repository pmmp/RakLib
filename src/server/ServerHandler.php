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

use pocketmine\utils\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use function chr;
use function ord;
use function strlen;
use function substr;

class ServerHandler{

	/** @var RakLibServer */
	protected $server;
	/** @var ServerInstance */
	protected $instance;

	/** @var InterThreadChannelReader */
	private $recvInternalChannel;

	/** @var InterThreadChannelWriter */
	private $sendInternalChannel;

	public function __construct(RakLibServer $server, ServerInstance $instance){
		$this->server = $server;
		$this->instance = $instance;

		//TODO: allow these to be injected?
		$this->recvInternalChannel = new InterThreadChannelReader($server->getExternalQueue());
		$this->sendInternalChannel = new InterThreadChannelWriter($server->getInternalQueue());
	}

	public function sendEncapsulated(int $identifier, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$buffer = chr(ITCProtocol::PACKET_ENCAPSULATED) . Binary::writeInt($identifier) . chr($flags) . $packet->toInternalBinary();
		$this->sendInternalChannel->write($buffer);
	}

	public function sendRaw(string $address, int $port, string $payload) : void{
		$buffer = chr(ITCProtocol::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
		$this->sendInternalChannel->write($buffer);
	}

	public function closeSession(int $identifier, string $reason) : void{
		$buffer = chr(ITCProtocol::PACKET_CLOSE_SESSION) . Binary::writeInt($identifier) . chr(strlen($reason)) . $reason;
		$this->sendInternalChannel->write($buffer);
	}

	/**
	 * @param string $name
	 * @param mixed  $value Must be castable to string
	 */
	public function sendOption(string $name, $value) : void{
		$buffer = chr(ITCProtocol::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->sendInternalChannel->write($buffer);
	}

	public function blockAddress(string $address, int $timeout) : void{
		$buffer = chr(ITCProtocol::PACKET_BLOCK_ADDRESS) . chr(strlen($address)) . $address . Binary::writeInt($timeout);
		$this->sendInternalChannel->write($buffer);
	}

	public function unblockAddress(string $address) : void{
		$buffer = chr(ITCProtocol::PACKET_UNBLOCK_ADDRESS) . chr(strlen($address)) . $address;
		$this->sendInternalChannel->write($buffer);
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->sendInternalChannel->write(chr(ITCProtocol::PACKET_RAW_FILTER) . $regex);
	}

	public function shutdown() : void{
		$buffer = chr(ITCProtocol::PACKET_SHUTDOWN);
		$this->sendInternalChannel->write($buffer);
		$this->server->shutdown();
		$this->server->join();
	}

	public function emergencyShutdown() : void{
		$this->server->shutdown();
		$this->sendInternalChannel->write(chr(ITCProtocol::PACKET_EMERGENCY_SHUTDOWN));
	}

	/**
	 * @return bool
	 */
	public function handlePacket() : bool{
		if(($packet = $this->recvInternalChannel->read()) !== null){
			$id = ord($packet[0]);
			$offset = 1;
			if($id === ITCProtocol::PACKET_ENCAPSULATED){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$buffer = substr($packet, $offset);
				$this->instance->handleEncapsulated($identifier, $buffer);
			}elseif($id === ITCProtocol::PACKET_RAW){
				$len = ord($packet[$offset++]);
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$this->instance->handleRaw($address, $port, $payload);
			}elseif($id === ITCProtocol::PACKET_SET_OPTION){
				$len = ord($packet[$offset++]);
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
				$this->instance->handleOption($name, $value);
			}elseif($id === ITCProtocol::PACKET_OPEN_SESSION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$len = ord($packet[$offset++]);
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$clientID = Binary::readLong(substr($packet, $offset, 8));
				$this->instance->openSession($identifier, $address, $port, $clientID);
			}elseif($id === ITCProtocol::PACKET_CLOSE_SESSION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$len = ord($packet[$offset++]);
				$reason = substr($packet, $offset, $len);
				$this->instance->closeSession($identifier, $reason);
			}elseif($id === ITCProtocol::PACKET_INVALID_SESSION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$this->instance->closeSession($identifier, "Invalid session");
			}elseif($id === ITCProtocol::PACKET_ACK_NOTIFICATION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$identifierACK = Binary::readInt(substr($packet, $offset, 4));
				$this->instance->notifyACK($identifier, $identifierACK);
			}elseif($id === ITCProtocol::PACKET_REPORT_PING){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$pingMS = Binary::readInt(substr($packet, $offset, 4));
				$this->instance->updatePing($identifier, $pingMS);
			}

			return true;
		}

		return false;
	}
}
