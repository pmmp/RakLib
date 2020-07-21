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

interface ServerEventListener{

	/**
	 * @param int    $sessionId
	 * @param string $address
	 * @param int    $port
	 * @param int    $clientID
	 */
	public function openSession(int $sessionId, string $address, int $port, int $clientID) : void;

	/**
	 * @param int    $sessionId
	 * @param string $reason
	 */
	public function closeSession(int $sessionId, string $reason) : void;

	/**
	 * @param int    $sessionId
	 * @param string $packet
	 */
	public function handleEncapsulated(int $sessionId, string $packet) : void;

	/**
	 * @param string $address
	 * @param int    $port
	 * @param string $payload
	 */
	public function handleRaw(string $address, int $port, string $payload) : void;

	/**
	 * @param int $sessionId
	 * @param int $identifierACK
	 */
	public function notifyACK(int $sessionId, int $identifierACK) : void;

	public function handleBandwidthStats(int $bytesSentDiff, int $bytesReceivedDiff) : void;

	/**
	 * @param int $sessionId
	 * @param int $pingMS
	 */
	public function updatePing(int $sessionId, int $pingMS) : void;
}
