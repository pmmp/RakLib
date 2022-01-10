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

use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use raklib\utils\InternetAddress;
use function assert;
use function count;
use function explode;
use function inet_ntop;
use function inet_pton;
use function strlen;
use const AF_INET6;

final class PacketSerializer extends BinaryStream{

	/**
	 * @throws BinaryDataException
	 */
	public function getString() : string{
		return $this->get($this->getShort());
	}

	/**
	 * @throws BinaryDataException
	 */
	public function getAddress() : InternetAddress{
		$version = $this->getByte();
		if($version === 4){
			$addr = ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff);
			$port = $this->getShort();
			return new InternetAddress($addr, $port, $version);
		}elseif($version === 6){
			//http://man7.org/linux/man-pages/man7/ipv6.7.html
			$this->getLShort(); //Family, AF_INET6
			$port = $this->getShort();
			$this->getInt(); //flow info
			$addr = inet_ntop($this->get(16));
			if($addr === false){
				throw new BinaryDataException("Failed to parse IPv6 address");
			}
			$this->getInt(); //scope ID
			return new InternetAddress($addr, $port, $version);
		}else{
			throw new BinaryDataException("Unknown IP address version $version");
		}
	}

	public function putString(string $v) : void{
		$this->putShort(strlen($v));
		$this->put($v);
	}

	public function putAddress(InternetAddress $address) : void{
		$version = $address->getVersion();
		$this->putByte($version);
		if($version === 4){
			$parts = explode(".", $address->getIp());
			assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
			foreach($parts as $b){
				$this->putByte((~((int) $b)) & 0xff);
			}
			$this->putShort($address->getPort());
		}elseif($version === 6){
			$this->putLShort(AF_INET6);
			$this->putShort($address->getPort());
			$this->putInt(0);
			$rawIp = inet_pton($address->getIp());
			if($rawIp === false){
				throw new \InvalidArgumentException("Invalid IPv6 address could not be encoded");
			}
			$this->put($rawIp);
			$this->putInt(0);
		}else{
			throw new \InvalidArgumentException("IP version $version is not supported");
		}
	}
}
