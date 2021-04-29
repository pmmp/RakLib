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
use raklib\server\ipc\UserToRakLibThreadSessionMessageProtocol as ITCSessionProtocol;
use raklib\server\SessionInterface;
use function ord;
use function substr;

final class UserToRakLibThreadSessionMessageReceiver{

	private InterThreadChannelReader $channel;
	private bool $closed = false;

	public function __construct(InterThreadChannelReader $channel){
		$this->channel = $channel;
	}

	public function process(SessionInterface $session) : bool{
		if(($packet = $this->channel->read()) !== null){
			$id = ord($packet[0]);
			$offset = 1;
			if($id === ITCSessionProtocol::PACKET_ENCAPSULATED){
				$flags = ord($packet[$offset++]);
				$immediate = ($flags & UserToRakLibThreadSessionMessageProtocol::ENCAPSULATED_FLAG_IMMEDIATE) !== 0;
				$needACK = ($flags & UserToRakLibThreadSessionMessageProtocol::ENCAPSULATED_FLAG_NEED_ACK) !== 0;

				$encapsulated = new EncapsulatedPacket();
				$encapsulated->reliability = ord($packet[$offset++]);

				if($needACK){
					$encapsulated->identifierACK = Binary::readInt(substr($packet, $offset, 4));
					$offset += 4;
				}

				if(PacketReliability::isSequencedOrOrdered($encapsulated->reliability)){
					$encapsulated->orderChannel = ord($packet[$offset++]);
				}

				$encapsulated->buffer = substr($packet, $offset);
				$session->sendEncapsulated($encapsulated, $immediate);
			}elseif($id === ITCSessionProtocol::PACKET_CLOSE_SESSION){
				$session->initiateDisconnect("server disconnect");
				$this->closed = true;
			}

			return true;
		}

		return false;
	}

	public function isClosed() : bool{ return $this->closed; }
}
