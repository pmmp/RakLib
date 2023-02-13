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

namespace raklib\generic;

final class DisconnectReason{
	public const CLIENT_DISCONNECT = 0;
	public const SERVER_DISCONNECT = 1;
	public const PEER_TIMEOUT = 2;
	public const CLIENT_RECONNECT = 3;
	public const SERVER_SHUTDOWN = 4; //TODO: do we really need a separate reason for this in addition to SERVER_DISCONNECT?

	public static function toString(int $reason) : string{
		return match($reason){
			self::CLIENT_DISCONNECT => "client disconnect",
			self::SERVER_DISCONNECT => "server disconnect",
			self::PEER_TIMEOUT => "timeout",
			self::CLIENT_RECONNECT => "new session established on same address and port",
			self::SERVER_SHUTDOWN => "server shutdown",
			default => "Unknown reason $reason"
		};
	}
}
