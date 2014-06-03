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

namespace raklib\protocol;

use raklib\Binary;

class EncapsulatedPacket{
	public $reliability;
	public $hasSplit = false;
	public $length = 0;
	public $messageIndex = null;
	public $orderIndex = null;
	public $orderChannel = null;
	public $splitCount = null;
	public $splitID = null;
	public $splitIndex = null;
	public $buffer;

	/**
	 * @param string $binary
	 * @param int    &$offset
	 *
	 * @return EncapsulatedPacket
	 */
	public static function fromBinary($binary, &$offset = null){
		$flags = ord($binary{0});
		$packet = new EncapsulatedPacket;
		$packet->reliability = $reliability = ($flags & 0b11100000) >> 5;
		$packet->hasSplit = $hasSplit = ($flags & 0b0010000) > 0;
		$length = (int) ceil(Binary::readShort(substr($binary, 1, 2), false) / 8);
		$offset = 3;

		if(
			$reliability === 2 or
			$reliability === 4 or
			$reliability === 6 or
			$reliability === 7
		){
			$packet->messageIndex = Binary::readTriad(strrev(substr($binary, $offset, 3)));
			$offset += 3;
		}

		if(
			$reliability === 1 or
			$reliability === 3 or
			$reliability === 4 or
			$reliability === 7
		){
			$packet->orderIndex = Binary::readTriad(strrev(substr($binary, $offset, 3)));
			$offset += 3;
			$packet->orderChannel = ord($binary{$offset});
			++$offset;
		}

		if($hasSplit){
			$packet->splitCount = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
			$packet->splitID = Binary::readShort(substr($binary, $offset, 2));
			$offset += 2;
			$packet->splitIndex = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
		}

		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return $packet;
	}

	public function getTotalLength(){
		return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) +  + ($this->hasSplit ? 9 : 0);
	}

	public function toBinary(){
		$binary = chr(($this->reliability << 5) | ($this->hasSplit ? 0b00010000 : 0));
		$binary .= Binary::writeShort(strlen($this->buffer) << 3);
		if(
			$this->reliability === 2 or
			$this->reliability === 4 or
			$this->reliability === 6 or
			$this->reliability === 7
		){
			$binary .= strrev(Binary::writeTriad($this->messageIndex));
		}

		if(
			$this->reliability === 1 or
			$this->reliability === 3 or
			$this->reliability === 4 or
			$this->reliability === 7
		){
			$binary .= strrev(Binary::writeTriad($this->orderIndex)) . chr($this->orderChannel);
		}

		if($this->hasSplit){
			$binary .= Binary::writeInt($this->splitCount) . Binary::writeShort($this->splitID) . Binary::writeInt($this->splitIndex);
		}

		return $binary . $this->buffer;
	}

	public function __toString(){
		return $this->toBinary();
	}
}