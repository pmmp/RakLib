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
use function ord;
use function substr;

final class RakLibToUserThreadMessageReceiver{
	/** @var ServerInstance */
	protected $instance;

	/** @var InterThreadChannelReader */
	private $channel;

	public function __construct(ServerInstance $instance, InterThreadChannelReader $channel){
		$this->instance = $instance;

		$this->channel = $channel;
	}

	/**
	 * @return bool
	 */
	public function handle() : bool{
		if(($packet = $this->channel->read()) !== null){
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
