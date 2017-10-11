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

namespace raklib\protocol;

#include <rules/RakLibPacket.h>

class OpenConnectionReply1 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_1;

	/** @var int */
	public $serverID;
	/** @var int */
	public $mtuSize;

	public function encode(){
		parent::encode();
		$this->writeMagic();
		$this->putLong($this->serverID);
		$this->putByte(0); //Server security
		$this->putShort($this->mtuSize);
	}

	public function decode(){
		parent::decode();
		$this->readMagic();
		$this->serverID = $this->getLong();
		$this->getByte(); //security
		$this->mtuSize = $this->getShort();
	}
}