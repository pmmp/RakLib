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

use pocketmine\utils\BinaryDataException;

#include <rules/RakLibPacket.h>

abstract class Packet{
	/** @var int */
	public static $ID = -1;

	public function encode(PacketSerializer $out) : void{
		$this->encodeHeader($out);
		$this->encodePayload($out);
	}

	protected function encodeHeader(PacketSerializer $out) : void{
		$out->putByte(static::$ID);
	}

	abstract protected function encodePayload(PacketSerializer $out) : void;

	/**
	 * @throws BinaryDataException
	 */
	public function decode(PacketSerializer $in) : void{
		$this->decodeHeader($in);
		$this->decodePayload($in);
	}

	/**
	 * @throws BinaryDataException
	 */
	protected function decodeHeader(PacketSerializer $in) : void{
		$in->getByte(); //PID
	}

	/**
	 * @throws BinaryDataException
	 */
	abstract protected function decodePayload(PacketSerializer $in) : void;
}
