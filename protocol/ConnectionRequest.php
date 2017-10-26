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

class ConnectionRequest extends Packet{
	public static $ID = MessageIdentifiers::ID_CONNECTION_REQUEST;

	/** @var int */
	public $clientID;
	/** @var int */
	public $sendPingTime;
	/** @var bool */
	public $useSecurity = false;

	public function encode(){
		parent::encode();
		$this->putLong($this->clientID);
		$this->putLong($this->sendPingTime);
		$this->putByte($this->useSecurity ? 1 : 0);
	}

	public function decode(){
		parent::decode();
		$this->clientID = $this->getLong();
		$this->sendPingTime = $this->getLong();
		$this->useSecurity = $this->getByte() > 0;
	}
}