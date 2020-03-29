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

namespace raklib\server;

use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;

interface ServerInterface{

	public function sendEncapsulated(int $identifier, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void;

	public function sendRaw(string $address, int $port, string $payload) : void;

	public function closeSession(int $identifier) : void;

	public function setOption(string $name, string $value) : void;

	public function blockAddress(string $address, int $timeout) : void;

	public function unblockAddress(string $address) : void;

	public function addRawPacketFilter(string $regex) : void;

	public function shutdown() : void;
}
