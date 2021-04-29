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
use raklib\server\ipc\RakLibToUserThreadSessionMessageProtocol as ITCSessionProtocol;
use raklib\server\SessionEventListener;
use function chr;
use function strlen;

final class RakLibToUserThreadSessionMessageSender implements SessionEventListener{

	private InterThreadChannelWriter $channel;

	public function __construct(InterThreadChannelWriter $channel){
		$this->channel = $channel;
	}

	public function onDisconnect(string $reason) : void{
		$this->channel->write(
			chr(ITCSessionProtocol::PACKET_CLOSE_SESSION) .
			chr(strlen($reason)) . $reason
		);
	}

	public function onPacketReceive(string $payload) : void{
		$this->channel->write(
			chr(ITCSessionProtocol::PACKET_ENCAPSULATED) .
			$payload
		);
	}

	public function onPacketAck(int $identifierACK) : void{
		$this->channel->write(
			chr(ITCSessionProtocol::PACKET_ACK_NOTIFICATION) .
			Binary::writeInt($identifierACK)
		);
	}

	public function onPingMeasure(int $pingMS) : void{
		$this->channel->write(
			chr(ITCSessionProtocol::PACKET_REPORT_PING) .
			Binary::writeInt($pingMS)
		);
	}
}
