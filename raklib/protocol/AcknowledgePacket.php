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

namespace raklib\protocol;


use raklib\Binary;

abstract class AcknowledgePacket extends Packet{
	/** @var int[] */
	public $packets = [];

	public function encode(){
		parent::encode();
		$payload = "";
		$records = 0;
		$pointer = 0;
		sort($this->packets, SORT_NUMERIC);
		$max = count($this->packets);

		while($pointer < $max){
			$type = true;
			$curr = $start = $this->packets[$pointer];
			for($i = $start + 1; $i < $max; ++$i){
				$n = $this->packets[$i];
				if(($n - $curr) === 1){
					$curr = $end = $n;
					$type = false;
					$pointer = $i + 1;
				}else{
					break;
				}
			}
			++$pointer;
			if($type === false and isset($end)){
				$payload .= "\x00";
				$payload .= strrev(Binary::writeTriad($start));
				$payload .= strrev(Binary::writeTriad($end));
			}else{
				$payload .= Binary::writeBool(true);
				$payload .= strrev(Binary::writeTriad($start));
			}
			++$records;
		}
		$this->putShort($records);
		$this->buffer .= $payload;
	}

	public function decode(){
		parent::decode();
		$count = $this->getShort();
		$this->packets = [];
		for($i = 0; $i < $count and !$this->feof(); ++$i){
			if($this->getByte() === 0){
				$start = $this->getLTriad();
				$end = $this->getLTriad();
				if(($end - $start) > 4096){
					$end = $start + 4096;
				}
				for($c = $start; $c <= $end; ++$c){
					$this->packets[] = $c;
				}
			}else{
				$this->packets[] = $this->getLTriad();
			}
		}
	}
}