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

class OpenConnectionReply2 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_2;

	/** @var int */
	public $serverID;
	/** @var string */
	public $clientAddress;
	/** @var int */
	public $clientPort;
	/** @var int */
	public $mtuSize;

	public function encode(){
		parent::encode();
		$this->writeMagic();
		$this->putLong($this->serverID);
		$this->putAddress($this->clientAddress, $this->clientPort, 4);
		$this->putShort($this->mtuSize);
		$this->putByte(0); //server security
	}

	public function decode(){
		parent::decode();
		$this->readMagic();
		$this->serverID = $this->getLong();
		$this->getAddress($this->clientAddress, $this->clientPort);
		$this->mtuSize = $this->getShort();
		$this->getByte(); //server security
	}
}
