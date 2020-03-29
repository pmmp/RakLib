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
use raklib\server\RakLibToUserThreadMessageProtocol as ITCProtocol;
use function chr;
use function strlen;

final class RakLibToUserThreadMessageSender implements ServerInstance{

	/** @var InterThreadChannelWriter */
	private $channel;

	public function __construct(InterThreadChannelWriter $channel){
		$this->channel = $channel;
	}

	public function openSession(int $sessionId, string $address, int $port, int $clientId) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_OPEN_SESSION) .
			Binary::writeInt($sessionId) .
			chr(strlen($address)) . $address .
			Binary::writeShort($port) .
			Binary::writeLong($clientId)
		);
	}

	public function closeSession(int $sessionId, string $reason) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_CLOSE_SESSION) .
			Binary::writeInt($sessionId) .
			chr(strlen($reason)) . $reason
		);
	}

	public function handleEncapsulated(int $sessionId, string $packet) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_ENCAPSULATED) .
			Binary::writeInt($sessionId) .
			$packet
		);
	}

	public function handleRaw(string $address, int $port, string $payload) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_RAW) .
			chr(strlen($address)) . $address .
			Binary::writeShort($port) .
			$payload
		);
	}

	public function notifyACK(int $sessionId, int $identifierACK) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_ACK_NOTIFICATION) .
			Binary::writeInt($sessionId) .
			Binary::writeInt($identifierACK)
		);
	}

	public function handleOption(string $name, string $value) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_SET_OPTION) .
			chr(strlen($name)) . $name .
			$value
		);
	}

	public function updatePing(int $sessionId, int $pingMS) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_REPORT_PING) .
			Binary::writeInt($sessionId) .
			Binary::writeInt($pingMS)
		);
	}
}