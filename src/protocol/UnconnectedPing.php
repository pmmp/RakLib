<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;

#include <rules/RakLibPacket.h>

class UnconnectedPing extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_UNCONNECTED_PING;

	/** @var int */
	public $sendPingTime;
	/** @var int */
	public $clientId;

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putLong($this->sendPingTime);
		$this->writeMagic($out);
		$out->putLong($this->clientId);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->sendPingTime = $in->getLong();
		$this->readMagic($in);
		$this->clientId = $in->getLong();
	}
}
