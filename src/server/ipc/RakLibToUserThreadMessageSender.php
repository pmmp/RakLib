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
use raklib\server\ipc\RakLibToUserThreadMessageProtocol as ITCProtocol;
use raklib\server\ServerEventListener;
use function chr;
use function inet_pton;
use function strlen;

final class RakLibToUserThreadMessageSender implements ServerEventListener{

	/** @var InterThreadChannelWriter */
	private $channel;

	public function __construct(InterThreadChannelWriter $channel){
		$this->channel = $channel;
	}

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientId) : void{
		$rawAddr = inet_pton($address);
		if($rawAddr === false){
			throw new \InvalidArgumentException("Invalid IP address");
		}
		$this->channel->write(
			chr(ITCProtocol::PACKET_OPEN_SESSION) .
			Binary::writeInt($sessionId) .
			chr(strlen($rawAddr)) . $rawAddr .
			Binary::writeShort($port) .
			Binary::writeLong($clientId)
		);
	}

	public function onClientDisconnect(int $sessionId, string $reason) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_CLOSE_SESSION) .
			Binary::writeInt($sessionId) .
			chr(strlen($reason)) . $reason
		);
	}

	public function onPacketReceive(int $sessionId, string $packet) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_ENCAPSULATED) .
			Binary::writeInt($sessionId) .
			$packet
		);
	}

	public function onRawPacketReceive(string $address, int $port, string $payload) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_RAW) .
			chr(strlen($address)) . $address .
			Binary::writeShort($port) .
			$payload
		);
	}

	public function onPacketAck(int $sessionId, int $identifierACK) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_ACK_NOTIFICATION) .
			Binary::writeInt($sessionId) .
			Binary::writeInt($identifierACK)
		);
	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_REPORT_BANDWIDTH_STATS) .
			Binary::writeLong($bytesSentDiff) .
			Binary::writeLong($bytesReceivedDiff)
		);
	}

	public function onPingMeasure(int $sessionId, int $pingMS) : void{
		$this->channel->write(
			chr(ITCProtocol::PACKET_REPORT_PING) .
			Binary::writeInt($sessionId) .
			Binary::writeInt($pingMS)
		);
	}
}
