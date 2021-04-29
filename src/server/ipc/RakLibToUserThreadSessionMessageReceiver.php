<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace raklib\server\ipc;

use pocketmine\utils\Binary;
use raklib\server\ipc\RakLibToUserThreadSessionMessageProtocol as ITCSessionProtocol;
use raklib\server\SessionEventListener;
use function ord;
use function substr;

final class RakLibToUserThreadSessionMessageReceiver{

	private InterThreadChannelReader $channel;
	private bool $closed = false;

	public function __construct(InterThreadChannelReader $channel){
		$this->channel = $channel;
	}

	public function process(SessionEventListener $listener) : bool{
		if(($packet = $this->channel->read()) !== null){
			$id = ord($packet[0]);
			$offset = 1;
			if($id === ITCSessionProtocol::PACKET_ENCAPSULATED){
				$buffer = substr($packet, $offset);
				$listener->onPacketReceive($buffer);
			}elseif($id === ITCSessionProtocol::PACKET_CLOSE_SESSION){
				$len = ord($packet[$offset++]);
				$reason = substr($packet, $offset, $len);
				$listener->onDisconnect($reason);
				$this->closed = true;
			}elseif($id === ITCSessionProtocol::PACKET_ACK_NOTIFICATION){
				$identifierACK = Binary::readInt(substr($packet, $offset, 4));
				$listener->onPacketAck($identifierACK);
			}elseif($id === ITCSessionProtocol::PACKET_REPORT_PING){
				$pingMS = Binary::readInt(substr($packet, $offset, 4));
				$listener->onPingMeasure($pingMS);
			}

			return true;
		}

		return false;
	}

	public function isClosed() : bool{ return $this->closed; }
}
