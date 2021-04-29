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

final class RakLibToUserThreadSessionMessageProtocol{

	/*
	 * ENCAPSULATED payload:
	 * byte[] (user packet payload)
	 */
	public const PACKET_ENCAPSULATED = 0x01;

	/*
	 * CLOSE_SESSION payload:
	 * string (reason)
	 */
	public const PACKET_CLOSE_SESSION = 0x02;

	/*
	 * ACK_NOTIFICATION payload:
	 * int32 (identifierACK)
	 */
	public const PACKET_ACK_NOTIFICATION = 0x03;

	/*
	 * REPORT_PING payload:
	 * int32 (measured latency in MS)
	 */
	public const PACKET_REPORT_PING = 0x04;

}
