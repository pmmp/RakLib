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

use raklib\RakLib;
use function str_repeat;
use function strlen;

class OpenConnectionRequest1 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1;

	public int $protocol = RakLib::DEFAULT_PROTOCOL_VERSION;
	public int $mtuSize;

	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putByte($this->protocol);
		$out->put(str_repeat("\x00", $this->mtuSize - strlen($out->getBuffer())));
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->readMagic($in);
		$this->protocol = $in->getByte();
		$this->mtuSize = strlen($in->getBuffer());
		$in->getRemaining(); //silence unread warnings
	}
}
