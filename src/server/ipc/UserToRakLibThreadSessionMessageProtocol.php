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

final class UserToRakLibThreadSessionMessageProtocol{
	public const ENCAPSULATED_FLAG_NEED_ACK = 1 << 0;
	public const ENCAPSULATED_FLAG_IMMEDIATE = 1 << 1;

	/*
	 * ENCAPSULATED payload:
	 * byte (flags, last 3 bits, priority)
	 * byte (reliability)
	 * int32 (ack identifier)
	 * byte? (order channel, only when sequenced or ordered reliability)
	 * byte[] (user packet payload)
	 */
	public const PACKET_ENCAPSULATED = 0x01;

	/* No payload */
	public const PACKET_CLOSE_SESSION = 0x02;
}
