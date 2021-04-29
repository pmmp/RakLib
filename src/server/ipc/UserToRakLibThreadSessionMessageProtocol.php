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
