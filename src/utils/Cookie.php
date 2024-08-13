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
use function random_int;
use function crc32;

final class CookieCache{

	/**
	 * @var array<string, int> $cookies
	 */
	private array $cookies = [];

	public function check(InternetAddress $address, int $cookie) : bool{
		$addressStr = $address->toString();

		if (isset($this->cookies[$addressStr])) {
			// If it checks the Cookie, it means that it is in the OpenConnectionRequest2 phase, and we can delete it from memory.
			if ($this->cookies[$addressStr] === $cookie) {
				unset($this->cookies[$addressStr]);
				return true;
			}
		} // Is there any chance that this is something else?
		return false;
	}

	public function add(InternetAddress $address) : int{
		$cookie = $this->generate($address);
		$this->cookies[$address->toString()] = $cookie;
		return $cookie;
	}

	private function generate(InternetAddress $address) : int{
		return random_int(0, 0xffffffff);
	}
}
