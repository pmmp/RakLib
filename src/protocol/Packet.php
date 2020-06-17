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
use function assert;
use function count;
use function explode;
use function inet_ntop;
use function inet_pton;
use function strlen;
use const AF_INET6;

#include <rules/RakLibPacket.h>

abstract class Packet{
	/** @var int */
	public static $ID = -1;

	/**
	 * @param BinaryStream $in
	 *
	 * @return string
	 * @throws BinaryDataException
	 */
	protected function getString(BinaryStream $in) : string{
		return $in->get($in->getShort());
	}

	/**
	 * @param BinaryStream $in
	 *
	 * @return InternetAddress
	 * @throws BinaryDataException
	 */
	protected function getAddress(BinaryStream $in) : InternetAddress{
		$version = $in->getByte();
		if($version === 4){
			$addr = ((~$in->getByte()) & 0xff) . "." . ((~$in->getByte()) & 0xff) . "." . ((~$in->getByte()) & 0xff) . "." . ((~$in->getByte()) & 0xff);
			$port = $in->getShort();
			return new InternetAddress($addr, $port, $version);
		}elseif($version === 6){
			//http://man7.org/linux/man-pages/man7/ipv6.7.html
			$in->getLShort(); //Family, AF_INET6
			$port = $in->getShort();
			$in->getInt(); //flow info
			$addr = inet_ntop($in->get(16));
			if($addr === false){
				throw new BinaryDataException("Failed to parse IPv6 address");
			}
			$in->getInt(); //scope ID
			return new InternetAddress($addr, $port, $version);
		}else{
			throw new BinaryDataException("Unknown IP address version $version");
		}
	}

	protected function putString(string $v, BinaryStream $out) : void{
		$out->putShort(strlen($v));
		$out->put($v);
	}

	protected function putAddress(InternetAddress $address, BinaryStream $out) : void{
		$out->putByte($address->version);
		if($address->version === 4){
			$parts = explode(".", $address->ip);
			assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
			foreach($parts as $b){
				$out->putByte((~((int) $b)) & 0xff);
			}
			$out->putShort($address->port);
		}elseif($address->version === 6){
			$out->putLShort(AF_INET6);
			$out->putShort($address->port);
			$out->putInt(0);
			$rawIp = inet_pton($address->ip);
			if($rawIp === false){
				throw new \InvalidArgumentException("Invalid IPv6 address could not be encoded");
			}
			$out->put($rawIp);
			$out->putInt(0);
		}else{
			throw new \InvalidArgumentException("IP version $address->version is not supported");
		}
	}

	public function encode(BinaryStream $out) : void{
		$this->encodeHeader($out);
		$this->encodePayload($out);
	}

	protected function encodeHeader(BinaryStream $out) : void{
		$out->putByte(static::$ID);
	}

	abstract protected function encodePayload(BinaryStream $out) : void;

	/**
	 * @param BinaryStream $in
	 *
	 * @throws BinaryDataException
	 */
	public function decode(BinaryStream $in) : void{
		$this->decodeHeader($in);
		$this->decodePayload($in);
	}

	/**
	 * @throws BinaryDataException
	 */
	protected function decodeHeader(BinaryStream $in) : void{
		$in->getByte(); //PID
	}

	/**
	 * @throws BinaryDataException
	 */
	abstract protected function decodePayload(BinaryStream $in) : void;
}
