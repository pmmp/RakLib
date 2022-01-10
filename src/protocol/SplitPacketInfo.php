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

final class SplitPacketInfo{
	/** @var int */
	private $id;
	/** @var int */
	private $partIndex;
	/** @var int */
	private $totalPartCount;

	public function __construct(int $id, int $partIndex, int $totalPartCount){
		//TODO: argument validation
		$this->id = $id;
		$this->partIndex = $partIndex;
		$this->totalPartCount = $totalPartCount;
	}

	public function getId() : int{
		return $this->id;
	}

	public function getPartIndex() : int{
		return $this->partIndex;
	}

	public function getTotalPartCount() : int{
		return $this->totalPartCount;
	}
}
