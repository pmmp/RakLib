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

namespace raklib\server;

use raklib\protocol\EncapsulatedPacket;

interface ServerInterface{

	public function sendEncapsulated(int $sessionId, EncapsulatedPacket $packet, bool $immediate = false) : void;

	public function sendRaw(string $address, int $port, string $payload) : void;

	public function closeSession(int $sessionId) : void;

	public function setName(string $name) : void;

	public function setPortCheck(bool $value) : void;

	public function setPacketsPerTickLimit(int $limit) : void;

	public function blockAddress(string $address, int $timeout) : void;

	public function unblockAddress(string $address) : void;

	public function addRawPacketFilter(string $regex) : void;
}
