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

namespace raklib\server\ipc;

use pocketmine\utils\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\server\ipc\UserToRakLibThreadMessageProtocol as ITCProtocol;
use raklib\server\ServerInterface;
use function chr;
use function strlen;

class UserToRakLibThreadMessageSender implements ServerInterface{
	/** @var InterThreadChannelWriter */
	private $channel;

	public function __construct(InterThreadChannelWriter $channel){
		$this->channel = $channel;
	}

	public function sendEncapsulated(int $sessionId, EncapsulatedPacket $packet, bool $immediate = false) : void{
		$flags =
			($immediate ? ITCProtocol::ENCAPSULATED_FLAG_IMMEDIATE : 0) |
			($packet->identifierACK !== null ? ITCProtocol::ENCAPSULATED_FLAG_NEED_ACK : 0);

		$buffer = chr(ITCProtocol::PACKET_ENCAPSULATED) .
			Binary::writeInt($sessionId) .
			chr($flags) .
			chr($packet->reliability) .
			($packet->identifierACK !== null ? Binary::writeInt($packet->identifierACK) : "") .
			(PacketReliability::isSequencedOrOrdered($packet->reliability) ? chr($packet->orderChannel) : "") .
			$packet->buffer;
		$this->channel->write($buffer);
	}

	public function sendRaw(string $address, int $port, string $payload) : void{
		$buffer = chr(ITCProtocol::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
		$this->channel->write($buffer);
	}

	public function closeSession(int $sessionId) : void{
		$buffer = chr(ITCProtocol::PACKET_CLOSE_SESSION) . Binary::writeInt($sessionId);
		$this->channel->write($buffer);
	}

	public function setName(string $name) : void{
		$this->channel->write(chr(ITCProtocol::PACKET_SET_NAME) . $name);
	}

	public function setPortCheck(bool $value) : void{
		$this->channel->write(chr($value ? ITCProtocol::PACKET_ENABLE_PORT_CHECK : ITCProtocol::PACKET_DISABLE_PORT_CHECK));
	}

	public function setPacketsPerTickLimit(int $limit) : void{
		$this->channel->write(chr(ITCProtocol::PACKET_SET_PACKETS_PER_TICK_LIMIT) . Binary::writeLong($limit));
	}

	public function blockAddress(string $address, int $timeout) : void{
		$buffer = chr(ITCProtocol::PACKET_BLOCK_ADDRESS) . chr(strlen($address)) . $address . Binary::writeInt($timeout);
		$this->channel->write($buffer);
	}

	public function unblockAddress(string $address) : void{
		$buffer = chr(ITCProtocol::PACKET_UNBLOCK_ADDRESS) . chr(strlen($address)) . $address;
		$this->channel->write($buffer);
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->channel->write(chr(ITCProtocol::PACKET_RAW_FILTER) . $regex);
	}
}
