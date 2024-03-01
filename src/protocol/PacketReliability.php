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

namespace raklib\protocol;

enum PacketReliability : int{

	/*
	 * From https://github.com/OculusVR/RakNet/blob/master/Source/PacketPriority.h
	 *
	 * Default: 0b010 (2) or 0b011 (3)
	 */

	case UNRELIABLE = 0;
	case UNRELIABLE_SEQUENCED = 1;
	case RELIABLE = 2;
	case RELIABLE_ORDERED = 3;
	case RELIABLE_SEQUENCED = 4;

	/* The following reliabilities are used in RakNet internals, but never sent on the wire. */
	case UNRELIABLE_WITH_ACK_RECEIPT = 5;
	case RELIABLE_WITH_ACK_RECEIPT = 6;
	case RELIABLE_ORDERED_WITH_ACK_RECEIPT = 7;

	public const MAX_ORDER_CHANNELS = 32;

	public function isReliable() : bool{
		return (
			$this === self::RELIABLE or
			$this === self::RELIABLE_ORDERED or
			$this === self::RELIABLE_SEQUENCED or
			$this === self::RELIABLE_WITH_ACK_RECEIPT or
			$this === self::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}

	public function isSequenced() : bool{
		return (
			$this === self::UNRELIABLE_SEQUENCED or
			$this === self::RELIABLE_SEQUENCED
		);
	}

	public function isOrdered() : bool{
		return (
			$this === self::RELIABLE_ORDERED or
			$this === self::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}

	public function isSequencedOrOrdered() : bool{
		return (
			$this === self::UNRELIABLE_SEQUENCED or
			$this === self::RELIABLE_ORDERED or
			$this === self::RELIABLE_SEQUENCED or
			$this === self::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}
}
