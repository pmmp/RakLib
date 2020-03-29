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
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\UserToRakLibThreadMessageProtocol as ITCProtocol;
use function chr;
use function strlen;

class ServerHandler{
	/** @var InterThreadChannelWriter */
	private $sendInternalChannel;

	public function __construct(InterThreadChannelWriter $sendChannel){
		$this->sendInternalChannel = $sendChannel;
	}

	public function sendEncapsulated(int $identifier, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$buffer = chr(ITCProtocol::PACKET_ENCAPSULATED) .
			Binary::writeInt($identifier) .
			chr($flags) .
			chr($packet->reliability) .
			Binary::writeInt($packet->identifierACK ?? -1) . //TODO: don't write this for non-ack-receipt reliabilities
			(PacketReliability::isSequencedOrOrdered($packet->reliability) ? chr($packet->orderChannel) : "") .
			$packet->buffer;
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
	}

	public function emergencyShutdown() : void{
		$this->sendInternalChannel->write(chr(ITCProtocol::PACKET_EMERGENCY_SHUTDOWN));
	}
}
