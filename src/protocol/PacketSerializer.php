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

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use raklib\utils\InternetAddress;
use function assert;
use function count;
use function explode;
use function inet_ntop;
use function inet_pton;
use function strlen;
use const AF_INET6;

final class PacketSerializer{
	private function __construct(){
		//NOOP
	}

	/**
	 * @throws DataDecodeException
	 */
	public static function getString(ByteBufferReader $in) : string{
		return $in->readByteArray(BE::readUnsignedShort($in));
	}

	/**
	 * @throws DataDecodeException
	 */
	public static function getAddress(ByteBufferReader $in) : InternetAddress{
		$version = Byte::readUnsigned($in);
		if($version === 4){
			$addr = ((~Byte::readUnsigned($in)) & 0xff) . "." . ((Byte::readUnsigned($in)) & 0xff) . "." . ((~Byte::readUnsigned($in)) & 0xff) . "." . ((~Byte::readUnsigned($in)) & 0xff);
			$port = BE::readUnsignedShort($in);
			return new InternetAddress($addr, $port, $version);
		}elseif($version === 6){
			//http://man7.org/linux/man-pages/man7/ipv6.7.html
			LE::readUnsignedShort($in); //Family, AF_INET6
			$port = BE::readUnsignedShort($in);
			BE::readUnsignedInt($in); //flow info
			$addr = inet_ntop($in->readByteArray(16));
			if($addr === false){
				throw new DataDecodeException("Failed to parse IPv6 address");
			}
			BE::readUnsignedInt($in); //scope ID
			return new InternetAddress($addr, $port, $version);
		}else{
			throw new DataDecodeException("Unknown IP address version $version");
		}
	}

	public static function putString(ByteBufferWriter $out, string $v) : void{
		BE::writeUnsignedShort($out, strlen($v));
		$out->writeByteArray($v);
	}

	public static function putAddress(ByteBufferWriter $out, InternetAddress $address) : void{
		$version = $address->getVersion();
		Byte::writeUnsigned($out, $version);
		if($version === 4){
			$parts = explode(".", $address->getIp());
			assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
			foreach($parts as $b){
				Byte::writeUnsigned($out, (~((int) $b)) & 0xff);
			}
			BE::writeUnsignedShort($out, $address->getPort());
		}elseif($version === 6){
			LE::writeUnsignedShort($out, AF_INET6);
			BE::writeUnsignedShort($out, $address->getPort());
			BE::writeUnsignedInt($out, 0);
			$rawIp = inet_pton($address->getIp());
			if($rawIp === false){
				throw new \InvalidArgumentException("Invalid IPv6 address could not be encoded");
			}
			$out->writeByteArray($rawIp);
			BE::writeUnsignedInt($out, 0);
		}else{
			throw new \InvalidArgumentException("IP version $version is not supported");
		}
	}
}
