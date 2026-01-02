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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

class AdvertiseSystem extends Packet{
	public static $ID = MessageIdentifiers::ID_ADVERTISE_SYSTEM;

	public string $serverName;

	protected function encodePayload(ByteBufferWriter $out) : void{
		PacketSerializer::putString($out, $this->serverName);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->serverName = PacketSerializer::getString($in);
	}
}
