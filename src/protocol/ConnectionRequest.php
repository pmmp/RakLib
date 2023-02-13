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

class ConnectionRequest extends ConnectedPacket{
	public static $ID = MessageIdentifiers::ID_CONNECTION_REQUEST;

	public int $clientID;
	public int $sendPingTime;
	public bool $useSecurity = false;

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putLong($this->clientID);
		$out->putLong($this->sendPingTime);
		$out->putByte($this->useSecurity ? 1 : 0);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->clientID = $in->getLong();
		$this->sendPingTime = $in->getLong();
		$this->useSecurity = $in->getByte() !== 0;
	}
}
