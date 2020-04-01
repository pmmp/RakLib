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
use pocketmine\utils\BinaryStream;
use raklib\utils\InternetAddress;
use function inet_ntop;
use function inet_pton;
use function strlen;
use const AF_INET6;

#ifndef COMPILE
use pocketmine\utils\Binary;
#endif

#include <rules/RakLibPacket.h>

abstract class Packet extends BinaryStream{
	/** @var int */
	public static $ID = -1;

	/** @var float|null */
	public $sendTime;

	/**
	 * @return string
	 * @throws BinaryDataException
	 */
	protected function getString() : string{
		return $this->get($this->getShort());
	}

	/**
	 * @return InternetAddress
	 * @throws BinaryDataException
	 */
	protected function getAddress() : InternetAddress{
		$version = $this->getByte();
		if($version === 4){
			$addr = inet_ntop(Binary::writeLInt((int) ~$this->getLInt()));
			if($addr === false){
				throw new BinaryDataException("Failed to parse IPv4 address");
			}
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

	protected function putString(string $v) : void{
		$this->putShort(strlen($v));
		$this->put($v);
	}

	protected function putAddress(InternetAddress $address) : void{
		$this->putByte($address->version);
		if($address->version === 4){
			$rawIp = inet_pton($address->ip);
			if($rawIp === false){
				throw new \InvalidArgumentException("Invalid IPv4 address could not be encoded");
			}
			$this->putLInt(Binary::readLInt($rawIp));
			$this->putShort($address->port);
		}elseif($address->version === 6){
			$this->putLShort(AF_INET6);
			$this->putShort($address->port);
			$this->putInt(0);
			$rawIp = inet_pton($address->ip);
			if($rawIp === false){
				throw new \InvalidArgumentException("Invalid IPv6 address could not be encoded");
			}
			$this->put($rawIp);
			$this->putInt(0);
		}else{
			throw new \InvalidArgumentException("IP version $address->version is not supported");
		}
	}

	public function encode() : void{
		$this->reset();
		$this->encodeHeader();
		$this->encodePayload();
	}

	protected function encodeHeader() : void{
		$this->putByte(static::$ID);
	}

	abstract protected function encodePayload() : void;

	/**
	 * @throws BinaryDataException
	 */
	public function decode() : void{
		$this->offset = 0;
		$this->decodeHeader();
		$this->decodePayload();
	}

	/**
	 * @throws BinaryDataException
	 */
	protected function decodeHeader() : void{
		$this->getByte(); //PID
	}

	/**
	 * @throws BinaryDataException
	 */
	abstract protected function decodePayload() : void;
}
