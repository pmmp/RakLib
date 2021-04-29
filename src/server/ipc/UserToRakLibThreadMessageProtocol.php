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
 * This interface contains descriptions of ITC packets used to transmit data into RakLib from the main thread.
 */
final class UserToRakLibThreadMessageProtocol{

	private function __construct(){
		//NOOP
	}

	/*
	 * Internal Packet:
	 * byte (packet ID)
	 * byte[] (payload)
	 */

	/*
	 * PACKET_OPEN_SESSION_RESPONSE payload:
	 * int32 (session ID)
	 * byte[] (serialized channel information)
	 */
	public const PACKET_OPEN_SESSION_RESPONSE = 0x01;

	/*
	 * RAW payload:
	 * byte (address length)
	 * byte[] (address from/to)
	 * short (port)
	 * byte[] (payload)
	 */
	public const PACKET_RAW = 0x04;

	/*
	 * BLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 * int (timeout)
	 */
	public const PACKET_BLOCK_ADDRESS = 0x05;

	/*
	 * UNBLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 */
	public const PACKET_UNBLOCK_ADDRESS = 0x06;

	/*
	 * RAW_FILTER payload:
	 * byte[] (pattern)
	 */
	public const PACKET_RAW_FILTER = 0x07;

	/*
	 * SET_NAME payload:
	 * byte[] (name)
	 */
	public const PACKET_SET_NAME = 0x08;

	/* No payload */
	public const PACKET_ENABLE_PORT_CHECK = 0x09;

	/* No payload */
	public const PACKET_DISABLE_PORT_CHECK = 0x10;

	/*
	 * PACKETS_PER_TICK_LIMIT payload:
	 * int64 (limit)
	 */
	public const PACKET_SET_PACKETS_PER_TICK_LIMIT = 0x11;
}
