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
use raklib\utils\InternetAddress;
use function strlen;

class NewIncomingConnection extends Packet{
	public static $ID = MessageIdentifiers::ID_NEW_INCOMING_CONNECTION;

	/** @var InternetAddress */
	public $address;

	/** @var InternetAddress[] */
	public $systemAddresses = [];

	/** @var int */
	public $sendPingTime;
	/** @var int */
	public $sendPongTime;

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putAddress($this->address);
		foreach($this->systemAddresses as $address){
			$out->putAddress($address);
		}
		$out->putLong($this->sendPingTime);
		$out->putLong($this->sendPongTime);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->address = $in->getAddress();

		//TODO: HACK!
		$stopOffset = strlen($in->getBuffer()) - 16; //buffer length - sizeof(sendPingTime) - sizeof(sendPongTime)
		$dummy = new InternetAddress("0.0.0.0", 0, 4);
		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			if($in->getOffset() >= $stopOffset){
				$this->systemAddresses[$i] = clone $dummy;
			}else{
				$this->systemAddresses[$i] = $in->getAddress();
			}
		}

		$this->sendPingTime = $in->getLong();
		$this->sendPongTime = $in->getLong();
	}
}
