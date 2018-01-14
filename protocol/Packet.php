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

#ifndef COMPILE
use pocketmine\utils\Binary;
#endif
use pocketmine\utils\BinaryStream;

#include <rules/BinaryIO.h>

abstract class Packet extends BinaryStream{
	public static $ID = -1;

	/** @var float|null */
	public $sendTime;

	protected function getString(){
		return $this->get($this->getShort());
	}

	protected function getAddress(&$addr, &$port, &$version = null){
		$version = $this->getByte();
		if($version === 4){
			$addr = ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff);
			$port = $this->getShort();
		}elseif($version === 6){
			//http://man7.org/linux/man-pages/man7/ipv6.7.html
			Binary::readLShort($this->get(2)); //Family, AF_INET6
			$port = $this->getShort();
			$this->getInt(); //flow info
			$addr = inet_ntop($this->get(16));
			$this->getInt(); //scope ID
		}else{
			throw new \UnexpectedValueException("Unknown IP address version $version");
		}
	}

	protected function putString($v){
		$this->putShort(strlen($v));
		$this->put($v);
	}

	protected function putAddress($addr, $port, $version = 4){
		$this->putByte($version);
		if($version === 4){
			foreach(explode(".", $addr) as $b){
				$this->putByte((~((int) $b)) & 0xff);
			}
			$this->putShort($port);
		}elseif($version === 6){
			$this->put(Binary::writeLShort(AF_INET6));
			$this->putShort($port);
			$this->putInt(0);
			$this->put(inet_pton($addr));
			$this->putInt(0);
		}else{
			throw new \InvalidArgumentException("IP version $version is not supported");
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

	public function decode() : void{
		$this->offset = 0;
		$this->decodeHeader();
		$this->decodePayload();
	}

	protected function decodeHeader() : void{
		$this->getByte(); //PID
	}

	abstract protected function decodePayload() : void;

	public function clean(){
		$this->buffer = null;
		$this->offset = 0;
		$this->sendTime = null;

		return $this;
	}
}
