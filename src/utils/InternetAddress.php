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

namespace raklib\utils;

use function inet_pton;
use function strlen;

class InternetAddress{
	/** @var string */
	public $ip;
	/** @var int */
	public $port;
	/** @var int */
	public $version;

	public function __construct(string $address, int $port, int $version){
		$encodedAddress = inet_pton($address);
		if($encodedAddress === false){
			throw new \InvalidArgumentException("Failed to parse internet address");
		}
		$this->ip = $address;

		if($port < 0 or $port > 65535){
			throw new \InvalidArgumentException("Invalid port range");
		}
		$this->port = $port;

		$addressLength = strlen($encodedAddress);
		if(!(($version === 4 and $addressLength === 4) or ($version === 6 and $addressLength === 16))){
			throw new \InvalidArgumentException("Given address does not match with version");
		}
		$this->version = $version;
	}

	/**
	 * @return string
	 */
	public function getIp() : string{
		return $this->ip;
	}

	/**
	 * @return int
	 */
	public function getPort() : int{
		return $this->port;
	}

	/**
	 * @return int
	 */
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
