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

class ConnectionRequestAccepted extends ConnectedPacket{
	public static $ID = MessageIdentifiers::ID_CONNECTION_REQUEST_ACCEPTED;

	public InternetAddress $address;
	/** @var InternetAddress[] */
	public array $systemAddresses = [];
	public int $sendPingTime;
	public int $sendPongTime;

	/**
	 * @param InternetAddress[] $systemAddresses
	 */
	public static function create(InternetAddress $clientAddress, array $systemAddresses, int $sendPingTime, int $sendPongTime) : self{
		$result = new self;
		$result->address = $clientAddress;
		$result->systemAddresses = $systemAddresses;
		$result->sendPingTime = $sendPingTime;
		$result->sendPongTime = $sendPongTime;
		return $result;
	}

	public function __construct(){
		$this->systemAddresses[] = new InternetAddress("127.0.0.1", 0, 4);
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		PacketSerializer::putAddress($out, $this->address);
		BE::writeUnsignedShort($out, 0);

		$dummy = new InternetAddress("0.0.0.0", 0, 4);
		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			PacketSerializer::putAddress($out, $this->systemAddresses[$i] ?? $dummy);
		}

		BE::writeUnsignedLong($out, $this->sendPingTime);
		BE::writeUnsignedLong($out, $this->sendPongTime);
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->address = PacketSerializer::getAddress($in);
		BE::readUnsignedShort($in); //TODO: check this

		$len = strlen($in->getData());
		$dummy = new InternetAddress("0.0.0.0", 0, 4);

		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			$this->systemAddresses[$i] = $in->getOffset() + 16 < $len ? PacketSerializer::getAddress($in) : $dummy; //HACK: avoids trying to read too many addresses on bad data
		}

		$this->sendPingTime = BE::readUnsignedLong($in);
		$this->sendPongTime = BE::readUnsignedLong($in);
	}
}
