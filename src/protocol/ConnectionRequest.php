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

class ConnectionRequest extends ConnectedPacket{
	public static $ID = MessageIdentifiers::ID_CONNECTION_REQUEST;

	public int $clientID;
	public int $sendPingTime;
	public bool $useSecurity = false;

	protected function encodePayload(ByteBufferWriter $out) : void{
		BE::writeUnsignedLong($out, $this->clientID);
		BE::writeUnsignedLong($out, $this->sendPingTime);
		Byte::writeUnsigned($out, $this->useSecurity ? 1 : 0);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->clientID = BE::readUnsignedLong($in);
		$this->sendPingTime = BE::readUnsignedLong($in);
		$this->useSecurity = Byte::readUnsigned($in) !== 0;
	}
}
