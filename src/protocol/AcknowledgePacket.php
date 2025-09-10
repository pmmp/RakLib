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

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use function count;
use function sort;
use function strlen;
use const SORT_NUMERIC;

abstract class AcknowledgePacket extends Packet{
	private const RECORD_TYPE_RANGE = 0;
	private const RECORD_TYPE_SINGLE = 1;

	/** @var int[] */
	public array $packets = [];

	protected function encodePayload(ByteBufferWriter $out) : void{
		$subWriter = new ByteBufferWriter();
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
						Byte::writeUnsigned($subWriter, self::RECORD_TYPE_SINGLE);
						LE::writeUnsignedTriad($subWriter, $start);
						$start = $last = $current;
					}else{
						Byte::writeUnsigned($subWriter, self::RECORD_TYPE_RANGE);
						LE::writeUnsignedTriad($subWriter, $start);
						LE::writeUnsignedTriad($subWriter, $last);
						$start = $last = $current;
					}
					++$records;
				}
			}

			if($start === $last){
				Byte::writeUnsigned($subWriter, self::RECORD_TYPE_SINGLE);
				LE::writeUnsignedTriad($subWriter, $start);
			}else{
				Byte::writeUnsigned($subWriter, self::RECORD_TYPE_RANGE);
				LE::writeUnsignedTriad($subWriter, $start);
				LE::writeUnsignedTriad($subWriter, $last);
			}
			++$records;
		}

		BE::writeUnsignedShort($out, $records);
		$out->writeByteArray($subWriter->getData());
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$count = BE::readUnsignedShort($in);
		$this->packets = [];
		$cnt = 0;
		$len = strlen($in->getData());
		for($i = 0; $i < $count and $in->getOffset() < $len and $cnt < 4096; ++$i){
			if(Byte::readUnsigned($in) === self::RECORD_TYPE_RANGE){
				$start = LE::readUnsignedTriad($in);
				$end = LE::readUnsignedTriad($in);
				if(($end - $start) > 512){
					$end = $start + 512;
				}
				for($c = $start; $c <= $end; ++$c){
					$this->packets[$cnt++] = $c;
				}
			}else{
				$this->packets[$cnt++] = LE::readUnsignedTriad($in);
			}
		}
	}
}
