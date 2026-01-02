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
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use raklib\utils\InternetAddress;

class OpenConnectionRequest2 extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2;

	private const TAIL_FIELDS_SIZE_COMMON = 2 + 8; //mtu + client ID
	private const TAIL_FIELDS_SIZE_IPV4 = self::TAIL_FIELDS_SIZE_COMMON + PacketSerializer::IPV4_SIZE;
	private const TAIL_FIELDS_SIZE_IPV6 = self::TAIL_FIELDS_SIZE_COMMON + PacketSerializer::IPV6_SIZE;

	public int $clientID;
	public InternetAddress $serverAddress;
	public ?int $cookie = null;
	public int $mtuSize;

	protected function encodePayload(ByteBufferWriter $out) : void{
		$this->writeMagic($out);
		if($this->cookie !== null){
			BE::writeUnsignedInt($out, $this->cookie);
			Byte::writeUnsigned($out, 0); //TODO: encryption challenge - not supported for now because RakNet sucks and we don't need it
		}
		PacketSerializer::putAddress($out, $this->serverAddress);
		BE::writeUnsignedShort($out, $this->mtuSize);
		BE::writeUnsignedLong($out, $this->clientID);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->readMagic($in);

		$remaining = $in->getUnreadLength();
		if($remaining !== self::TAIL_FIELDS_SIZE_IPV4 && $remaining !== self::TAIL_FIELDS_SIZE_IPV6){
			$this->cookie = BE::readUnsignedInt($in);
			//TODO: encryption challenge - not supported for now because RakNet sucks and we don't need it
			//we could handle this by looking at the remaining length of the packet, but it's not worth the complexity
			Byte::readUnsigned($in);
		}
		$this->serverAddress = PacketSerializer::getAddress($in);
		$this->mtuSize = BE::readUnsignedShort($in);
		$this->clientID = BE::readUnsignedLong($in);
	}
}
