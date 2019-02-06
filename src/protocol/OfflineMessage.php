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

abstract class OfflineMessage extends Packet{

	/**
	 * Magic bytes used to distinguish offline messages from loose garbage.
	 */
	private const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

	/** @var string */
	protected $magic;

	/**
	 * @throws BinaryDataException
	 */
	protected function readMagic(){
		$this->magic = $this->get(16);
	}

	protected function writeMagic(){
		$this->put(self::MAGIC);
	}

	public function isValid() : bool{
		return $this->magic === self::MAGIC;
	}

}
