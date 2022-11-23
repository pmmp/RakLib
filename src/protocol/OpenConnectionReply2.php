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

use raklib\utils\InternetAddress;

class OpenConnectionReply2 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_2;

	public int $serverID;
	public InternetAddress $clientAddress;
	public int $mtuSize;
	public bool $serverSecurity = false;

	public static function create(int $serverId, InternetAddress $clientAddress, int $mtuSize, bool $serverSecurity) : self{
		$result = new self;
		$result->serverID = $serverId;
		$result->clientAddress = $clientAddress;
		$result->mtuSize = $mtuSize;
		$result->serverSecurity = $serverSecurity;
		return $result;
	}

	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putLong($this->serverID);
		$out->putAddress($this->clientAddress);
		$out->putShort($this->mtuSize);
		$out->putByte($this->serverSecurity ? 1 : 0);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->readMagic($in);
		$this->serverID = $in->getLong();
		$this->clientAddress = $in->getAddress();
		$this->mtuSize = $in->getShort();
		$this->serverSecurity = $in->getByte() !== 0;
	}
}
