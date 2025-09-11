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

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;

abstract class Packet{
	/** @var int */
	public static $ID = -1;

	public function encode(ByteBufferWriter $out) : void{
		$this->encodeHeader($out);
		$this->encodePayload($out);
	}

	protected function encodeHeader(ByteBufferWriter $out) : void{
		Byte::writeUnsigned($out, static::$ID);
	}

	abstract protected function encodePayload(ByteBufferWriter $out) : void;

	/**
	 * @throws DataDecodeException
	 */
	public function decode(ByteBufferReader $in) : void{
		$this->decodeHeader($in);
		$this->decodePayload($in);
	}

	/**
	 * @throws DataDecodeException
	 */
	protected function decodeHeader(ByteBufferReader $in) : void{
		Byte::readUnsigned($in); //PID
	}

	/**
	 * @throws DataDecodeException
	 */
	abstract protected function decodePayload(ByteBufferReader $in) : void;
}
