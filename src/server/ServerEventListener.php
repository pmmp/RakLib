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

use raklib\generic\DisconnectReason;

interface ServerEventListener{

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void;

	/**
	 * @param int $reason one of the DisconnectReason constants
	 * @phpstan-param DisconnectReason::* $reason
	 *
	 * @see DisconnectReason
	 */
	public function onClientDisconnect(int $sessionId, int $reason) : void;

	public function onPacketReceive(int $sessionId, string $packet) : void;

	public function onRawPacketReceive(string $address, int $port, string $payload) : void;

	public function onPacketAck(int $sessionId, int $identifierACK) : void;

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void;

	public function onPingMeasure(int $sessionId, int $pingMS) : void;
}
