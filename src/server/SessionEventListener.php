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

interface SessionEventListener{

	/**
	 * Called when the client disconnects, or when RakLib terminates the connection (e.g. due to a timeout).
	 */
	public function onDisconnect(string $reason) : void;

	/**
	 * Called when a non-RakNet packet is received (user packet).
	 */
	public function onPacketReceive(string $payload) : void;

	/**
	 * Called when a packet that was sent with a requested ACK receipt is ACKed by the recipient.
	 */
	public function onPacketAck(int $identifierACK) : void;

	/**
	 * Called when RakLib records a new ping measurement for the session.
	 */
	public function onPingMeasure(int $pingMS) : void;
}
