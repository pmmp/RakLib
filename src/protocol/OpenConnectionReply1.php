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

class OpenConnectionReply1 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_1;

	public int $serverID;
	public ?int $cookie = null;
	public int $mtuSize;

	public static function create(int $serverId, ?int $cookie, int $mtuSize) : self{
		$result = new self;
		$result->serverID = $serverId;
		$result->cookie = $cookie;
		$result->mtuSize = $mtuSize;
		return $result;
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		$this->writeMagic($out);
		BE::writeUnsignedLong($out, $this->serverID);
		if($this->cookie !== null){
			Byte::writeUnsigned($out, 1);
			BE::writeUnsignedInt($out, $this->cookie);
			//TODO: If the client supports libcat security, we're expected to send a public key here.
			//However this would require context-specific logic and I really cba with it
		}else{
			Byte::writeUnsigned($out, 0);
		}
		BE::writeUnsignedShort($out, $this->mtuSize);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->readMagic($in);
		$this->serverID = BE::readUnsignedLong($in);
		if(Byte::readUnsigned($in) !== 0){
			$this->cookie = BE::readUnsignedInt($in);
			//TODO: If the server supports libcat security, it'll send a public key here.
		}else{
			$this->cookie = null;
		}
		$this->mtuSize = BE::readUnsignedShort($in);
	}
}
