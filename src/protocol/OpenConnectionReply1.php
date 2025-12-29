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

	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putLong($this->serverID);
		if($this->cookie !== null){
			$out->putByte(1);
			$out->putInt($this->cookie);
			//TODO: If the client supports libcat security, we're expected to send a public key here.
			//However this would require context-specific logic and I really cba with it
		}else{
			$out->putByte(0);
		}
		$out->putShort($this->mtuSize);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->readMagic($in);
		$this->serverID = $in->getLong();
		if($in->getByte() !== 0){
			$this->cookie = $in->getInt();
			//TODO: If the server supports libcat security, it'll send a public key here.
		}else{
			$this->cookie = null;
		}
		$this->mtuSize = $in->getShort();
	}
}
