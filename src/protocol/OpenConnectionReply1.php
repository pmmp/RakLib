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
	public bool $serverSecurity = false;
	public int $mtuSize;

	public static function create(int $serverId, bool $serverSecurity, int $mtuSize) : self{
		$result = new self;
		$result->serverID = $serverId;
		$result->serverSecurity = $serverSecurity;
		$result->mtuSize = $mtuSize;
		return $result;
	}

	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putLong($this->serverID);
		$out->putByte($this->serverSecurity ? 1 : 0);
		$out->putShort($this->mtuSize);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->readMagic($in);
		$this->serverID = $in->getLong();
		$this->serverSecurity = $in->getByte() !== 0;
		$this->mtuSize = $in->getShort();
	}
}
