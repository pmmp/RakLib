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

namespace raklib\server;

final class SimpleProtocolAcceptor implements ProtocolAcceptor{

	public function __construct(
		private int $protocolVersion
	){}

	public function accepts(int $protocolVersion) : bool{
		return $this->protocolVersion === $protocolVersion;
	}

	public function getPrimaryVersion() : int{
		return $this->protocolVersion;
	}
}
