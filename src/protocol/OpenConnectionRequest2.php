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

class OpenConnectionRequest2 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2;

	public int $clientID;
	public InternetAddress $serverAddress;
	public int $mtuSize;

	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putAddress($this->serverAddress);
		$out->putShort($this->mtuSize);
		$out->putLong($this->clientID);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->readMagic($in);
		$this->serverAddress = $in->getAddress();
		$this->mtuSize = $in->getShort();
		$this->clientID = $in->getLong();
	}
}
