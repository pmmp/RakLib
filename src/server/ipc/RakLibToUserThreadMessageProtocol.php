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
	 * ENCAPSULATED payload:
	 * int32 (internal session ID)
	 * byte[] (user packet payload)
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
	 * ACK_NOTIFICATION payload:
	 * int32 (internal session ID)
	 * int32 (identifierACK)
	 */
	public const PACKET_ACK_NOTIFICATION = 0x04;

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

	/*
	 * REPORT_PING payload:
	 * int32 (internal session ID)
	 * int32 (measured latency in MS)
	 */
	public const PACKET_REPORT_PING = 0x07;

}
