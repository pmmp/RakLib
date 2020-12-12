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
use function inet_ntop;
use function ord;
use function substr;

final class RakLibToUserThreadMessageReceiver{
	/** @var InterThreadChannelReader */
	private $channel;

	public function __construct(InterThreadChannelReader $channel){
		$this->channel = $channel;
	}

	public function handle(ServerEventListener $listener) : bool{
		if(($packet = $this->channel->read()) !== null){
			$id = ord($packet[0]);
			$offset = 1;
			if($id === ITCProtocol::PACKET_ENCAPSULATED){
				$sessionId = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$buffer = substr($packet, $offset);
				$listener->onPacketReceive($sessionId, $buffer);
			}elseif($id === ITCProtocol::PACKET_RAW){
				$len = ord($packet[$offset++]);
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$listener->onRawPacketReceive($address, $port, $payload);
			}elseif($id === ITCProtocol::PACKET_REPORT_BANDWIDTH_STATS){
				$sentBytes = Binary::readLong(substr($packet, $offset, 8));
				$offset += 8;
				$receivedBytes = Binary::readLong(substr($packet, $offset, 8));
				$listener->onBandwidthStatsUpdate($sentBytes, $receivedBytes);
			}elseif($id === ITCProtocol::PACKET_OPEN_SESSION){
				$sessionId = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$len = ord($packet[$offset++]);
				$rawAddr = substr($packet, $offset, $len);
				$offset += $len;
				$address = inet_ntop($rawAddr);
				if($address === false){
					throw new \RuntimeException("Unexpected invalid IP address in inter-thread message");
				}
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$clientID = Binary::readLong(substr($packet, $offset, 8));
				$listener->onClientConnect($sessionId, $address, $port, $clientID);
			}elseif($id === ITCProtocol::PACKET_CLOSE_SESSION){
				$sessionId = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$len = ord($packet[$offset++]);
				$reason = substr($packet, $offset, $len);
				$listener->onClientDisconnect($sessionId, $reason);
			}elseif($id === ITCProtocol::PACKET_ACK_NOTIFICATION){
				$sessionId = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$identifierACK = Binary::readInt(substr($packet, $offset, 4));
				$listener->onPacketAck($sessionId, $identifierACK);
			}elseif($id === ITCProtocol::PACKET_REPORT_PING){
				$sessionId = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$pingMS = Binary::readInt(substr($packet, $offset, 4));
				$listener->onPingMeasure($sessionId, $pingMS);
			}

			return true;
		}

		return false;
	}
}
