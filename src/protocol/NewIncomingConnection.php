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

use pmmp\encoding\BE;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use raklib\RakLib;
use raklib\utils\InternetAddress;
use function strlen;

class NewIncomingConnection extends ConnectedPacket{
	public static $ID = MessageIdentifiers::ID_NEW_INCOMING_CONNECTION;

	public InternetAddress $address;
	/** @var InternetAddress[] */
	public array $systemAddresses = [];
	public int $sendPingTime;
	public int $sendPongTime;

	protected function encodePayload(ByteBufferWriter $out) : void{
		PacketSerializer::putAddress($out, $this->address);
		foreach($this->systemAddresses as $address){
			PacketSerializer::putAddress($out, $address);
		}
		BE::writeUnsignedLong($out, $this->sendPingTime);
		BE::writeUnsignedLong($out, $this->sendPongTime);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->address = PacketSerializer::getAddress($in);

		//TODO: HACK!
		$stopOffset = strlen($in->getData()) - 16; //buffer length - sizeof(sendPingTime) - sizeof(sendPongTime)
		$dummy = new InternetAddress("0.0.0.0", 0, 4);
		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			if($in->getOffset() >= $stopOffset){
				$this->systemAddresses[$i] = clone $dummy;
			}else{
				$this->systemAddresses[$i] = PacketSerializer::getAddress($in);
			}
		}

		$this->sendPingTime = BE::readUnsignedLong($in);
		$this->sendPongTime = BE::readUnsignedLong($in);
	}
}
