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
						$payload .= "\x01";
						$payload .= strrev(Binary::writeTriad($start));
						$start = $last = $current;
					}else{
						$payload .= "\x00";
						$payload .= strrev(Binary::writeTriad($start));
						$payload .= strrev(Binary::writeTriad($last));
						$start = $last = $current;
					}
					++$records;
				}
			}

			if($start === $last){
				$payload .= "\x01";
				$payload .= strrev(Binary::writeTriad($start));
			}else{
				$payload .= "\x00";
				$payload .= strrev(Binary::writeTriad($start));
				$payload .= strrev(Binary::writeTriad($last));
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