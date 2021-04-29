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

namespace raklib\server\ipc;

/**
 * @internal
 * This interface contains descriptions of ITC packets used to transmit data from RakLib to the main thread.
 */
final class RakLibToUserThreadMessageProtocol{

	private function __construct(){
		//NOOP
	}

	/*
	 * Internal Packet:
	 * byte (packet ID)
	 * byte[] (payload)
	 */

	/*
	 * OPEN_SESSION payload:
	 * int32 (internal session ID)
	 * byte (address length)
	 * byte[] (address)
	 * short (port)
	 * long (clientID)
	 */
	public const PACKET_OPEN_SESSION = 0x02;

	/*
	 * REPORT_BANDWIDTH_STATS payload:
	 * int64 (sent bytes diff)
	 * int64 (received bytes diff)
	 */
	public const PACKET_REPORT_BANDWIDTH_STATS = 0x05;

	/*
	 * RAW payload:
	 * byte (address length)
	 * byte[] (address from/to)
	 * short (port)
	 * byte[] (payload)
	 */
	public const PACKET_RAW = 0x06;
}
