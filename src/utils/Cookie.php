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

namespace raklib\utils;

use raklib\utils\InternetAddress;
use pocketmine\utils\Binary;
use function mt_rand;
use function crc32;

final class Cookie{

	/**
	 * @var array<string, int> $cookies
	 */
	private array $cookies = [];

	public function get(InternetAddress $address) : int{
		if (isset($this->cookies[$address->toString()])) {
			return $this->cookies[$address->toString()];
		}
		return 0;
	}

	public static function setServerSecurity(bool $security) : ?Cookie {
		if ($security) {
			return new Cookie();
		}
		return null;
	}

	public function check(InternetAddress $address, int $cookie) : bool{
		$addressStr = $address->toString();

		if (isset($this->cookies[$addressStr])) {
			// If it checks the Cookie, it means that it is in the OpenConnectionRequest2 phase, and we can delete it from memory.
			unset($this->cookies[$addressStr]);
			if ($this->cookies[$addressStr] == $cookie) {
				return true;
			}
		} // Is there any chance that this is something else?
		return false;
	}

	public function add(InternetAddress $address) : void{
		if (!isset($this->cookies[$address->toString()])) {
			$this->cookies[$address->toString()] = $this->generate($address);
		}
	}

	private function generate(InternetAddress $address) : int{
		return crc32(Binary::writeLInt(mt_rand(0, 0xFFFFFFFF)) . Binary::writeLShort($address->getPort()) . $address->getIp());
	}
}
