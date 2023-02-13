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

final class InternetAddress{
	public function __construct(
		private string $ip,
		private int $port,
		private int $version
	){
		if($port < 0 or $port > 65535){
			throw new \InvalidArgumentException("Invalid port range");
		}
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getVersion() : int{
		return $this->version;
	}

	public function __toString(){
		return $this->ip . " " . $this->port;
	}

	public function toString() : string{
		return $this->__toString();
	}

	public function equals(InternetAddress $address) : bool{
		return $this->ip === $address->ip and $this->port === $address->port and $this->version === $address->version;
	}
}
