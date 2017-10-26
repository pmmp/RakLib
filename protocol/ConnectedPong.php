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

class ConnectedPong extends Packet{
	public static $ID = MessageIdentifiers::ID_CONNECTED_PONG;

	/** @var int */
	public $sendPingTime;
	/** @var int */
	public $sendPongTime;

	public function encode(){
		parent::encode();
		$this->putLong($this->sendPingTime);
		$this->putLong($this->sendPongTime);
	}

	public function decode(){
		parent::decode();
		$this->sendPingTime = $this->getLong();
		$this->sendPongTime = $this->getLong();
	}
}
