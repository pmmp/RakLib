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

use raklib\RakLib;

class NewIncomingConnection extends Packet{
	public static $ID = MessageIdentifiers::ID_NEW_INCOMING_CONNECTION;

	/** @var string */
	public $address;
	/** @var int */
	public $port;

	/** @var array */
	public $systemAddresses = [];

	/** @var int */
	public $sendPingTime;
	/** @var int */
	public $sendPongTime;

	protected function encodePayload() : void{
		//TODO
	}

	protected function decodePayload() : void{
		$this->getAddress($this->address, $this->port);

		//TODO: HACK!
		$stopOffset = strlen($this->buffer) - 16; //buffer length - sizeof(sendPingTime) - sizeof(sendPongTime)
		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			if($this->offset >= $stopOffset){
				$this->systemAddresses[$i] = ["0.0.0.0", 0, 4];
			}else{
				$this->getAddress($addr, $port, $version);
				$this->systemAddresses[$i] = [$addr, $port, $version];
			}
		}

		$this->sendPingTime = $this->getLong();
		$this->sendPongTime = $this->getLong();
	}
}
