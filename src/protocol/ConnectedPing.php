<?php

/*
 * This file is part of RakLib.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/RakLib>
 *
 * RakLib is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * RakLib is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace raklib\protocol;

class ConnectedPing extends ConnectedPacket{
	public static $ID = MessageIdentifiers::ID_CONNECTED_PING;

	public int $sendPingTime;

	public static function create(int $sendPingTime) : self{
		$result = new self;
		$result->sendPingTime = $sendPingTime;
		return $result;
	}

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putLong($this->sendPingTime);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->sendPingTime = $in->getLong();
	}
}
