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

namespace raklib;

abstract class RakLib{
	/**
	 * Default vanilla RakNet protocol version that this library implements. Things using RakLib can override this
	 * protocol version with something different.
	 */
	public const DEFAULT_PROTOCOL_VERSION = 6;

	/** Regular RakNet uses 10 by default. MCPE uses 20. Configure this value as appropriate. */
	public static int $SYSTEM_ADDRESS_COUNT = 20;
}
