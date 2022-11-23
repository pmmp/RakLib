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

use pocketmine\utils\Binary;
use function chr;
use function count;
use function sort;
use const SORT_NUMERIC;

abstract class AcknowledgePacket extends Packet{
	private const RECORD_TYPE_RANGE = 0;
	private const RECORD_TYPE_SINGLE = 1;

	/** @var int[] */
	public array $packets = [];

	protected function encodePayload(PacketSerializer $out) : void{
		$payload = "";
		sort($this->packets, SORT_NUMERIC);
		$count = count($this->packets);
		$records = 0;

		if($count > 0){
			$pointer = 1;
			$start = $this->packets[0];
			$last = $this->packets[0];

			while($pointer < $count){
				$current = $this->packets[$pointer++];
				$diff = $current - $last;
				if($diff === 1){
					$last = $current;
				}elseif($diff > 1){ //Forget about duplicated packets (bad queues?)
					if($start === $last){
						$payload .= chr(self::RECORD_TYPE_SINGLE);
						$payload .= Binary::writeLTriad($start);
						$start = $last = $current;
					}else{
						$payload .= chr(self::RECORD_TYPE_RANGE);
						$payload .= Binary::writeLTriad($start);
						$payload .= Binary::writeLTriad($last);
						$start = $last = $current;
					}
					++$records;
				}
			}

			if($start === $last){
				$payload .= chr(self::RECORD_TYPE_SINGLE);
				$payload .= Binary::writeLTriad($start);
			}else{
				$payload .= chr(self::RECORD_TYPE_RANGE);
				$payload .= Binary::writeLTriad($start);
				$payload .= Binary::writeLTriad($last);
			}
			++$records;
		}

		$out->putShort($records);
		$out->put($payload);
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$count = $in->getShort();
		$this->packets = [];
		$cnt = 0;
		for($i = 0; $i < $count and !$in->feof() and $cnt < 4096; ++$i){
			if($in->getByte() === self::RECORD_TYPE_RANGE){
				$start = $in->getLTriad();
				$end = $in->getLTriad();
				if(($end - $start) > 512){
					$end = $start + 512;
				}
				for($c = $start; $c <= $end; ++$c){
					$this->packets[$cnt++] = $c;
				}
			}else{
				$this->packets[$cnt++] = $in->getLTriad();
			}
		}
	}
}
