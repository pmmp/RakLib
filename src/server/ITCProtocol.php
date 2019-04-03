<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace raklib\server;

/**
 * @internal
 * This interface contains descriptions of ITC packets used to transmit data into RakLib from the main thread.
 */
interface ITCProtocol{

	/*
	 * These internal "packets" DO NOT exist in the RakNet protocol. They are used by the RakLib API to communicate
	 * messages between the RakLib thread and the implementation's thread.
	 *
	 * Internal Packet:
	 * byte (packet ID)
	 * byte[] (payload)
	 */

	/*
	 * ENCAPSULATED payload:
	 * int32 (internal session ID)
	 * byte (flags, last 3 bits, priority)
	 * payload (binary internal EncapsulatedPacket)
	 */
	public const PACKET_ENCAPSULATED = 0x01;

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
	 * CLOSE_SESSION payload:
	 * int32 (internal session ID)
	 * string (reason)
	 */
	public const PACKET_CLOSE_SESSION = 0x03;

	/*
	 * INVALID_SESSION payload:
	 * int32 (internal session ID)
	 */
	public const PACKET_INVALID_SESSION = 0x04;

	/* TODO: implement this
	 * SEND_QUEUE payload:
	 * int32 (internal session ID)
	 */
	public const PACKET_SEND_QUEUE = 0x05;

	/*
	 * ACK_NOTIFICATION payload:
	 * int32 (internal session ID)
	 * int32 (identifierACK)
	 */
	public const PACKET_ACK_NOTIFICATION = 0x06;

	/*
	 * SET_OPTION payload:
	 * byte (option name length)
	 * byte[] (option name)
	 * byte[] (option value)
	 */
	public const PACKET_SET_OPTION = 0x07;

	/*
	 * RAW payload:
	 * byte (address length)
	 * byte[] (address from/to)
	 * short (port)
	 * byte[] (payload)
	 */
	public const PACKET_RAW = 0x08;

	/*
	 * BLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 * int (timeout)
	 */
	public const PACKET_BLOCK_ADDRESS = 0x09;

	/*
	 * UNBLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 */
	public const PACKET_UNBLOCK_ADDRESS = 0x10;

	/*
	 * REPORT_PING payload:
	 * int32 (internal session ID)
	 * int32 (measured latency in MS)
	 */
	public const PACKET_REPORT_PING = 0x11;

	/*
	 * RAW_FILTER payload:
	 * byte[] (pattern)
	 */
	public const PACKET_RAW_FILTER = 0x12;

	/*
	 * No payload
	 *
	 * Sends the disconnect message, removes sessions correctly, closes sockets.
	 */
	public const PACKET_SHUTDOWN = 0x7e;

	/*
	 * No payload
	 *
	 * Leaves everything as-is and halts, other Threads can be in a post-crash condition.
	 */
	public const PACKET_EMERGENCY_SHUTDOWN = 0x7f;
}
