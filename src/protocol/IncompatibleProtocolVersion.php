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

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

class IncompatibleProtocolVersion extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_INCOMPATIBLE_PROTOCOL_VERSION;

	public int $protocolVersion;
	public int $serverId;

	public static function create(int $protocolVersion, int $serverId) : self{
		$result = new self;
		$result->protocolVersion = $protocolVersion;
		$result->serverId = $serverId;
		return $result;
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		Byte::writeUnsigned($out, $this->protocolVersion);
		$this->writeMagic($out);
		BE::writeUnsignedLong($out, $this->serverId);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->protocolVersion = Byte::readUnsigned($in);
		$this->readMagic($in);
		$this->serverId = BE::readUnsignedLong($in);
	}
}
