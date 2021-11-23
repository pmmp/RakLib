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

declare(strict_types=1);

namespace raklib\protocol;

use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use function ceil;
use function chr;
use function strlen;

#ifndef COMPILE
use pocketmine\utils\Binary;
#endif

#include <rules/RakLibPacket.h>

class EncapsulatedPacket{
	private const RELIABILITY_SHIFT = 5;
	private const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	private const SPLIT_FLAG = 0b00010000;

	/** @var int */
	public $reliability;
	/** @var int|null */
	public $messageIndex;
	/** @var int|null */
	public $sequenceIndex;
	/** @var int|null */
	public $orderIndex;
	/** @var int|null */
	public $orderChannel;
	/** @var SplitPacketInfo|null */
	public $splitInfo = null;
	/** @var string */
	public $buffer = "";
	/** @var int|null */
	public $identifierACK = null;

	/**
	 * @throws BinaryDataException
	 */
	public static function fromBinary(BinaryStream $stream) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$flags = $stream->getByte();
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$hasSplit = ($flags & self::SPLIT_FLAG) > 0;

		$length = $stream->getShort() >> 3;
		if($length === 0){
			throw new BinaryDataException("Encapsulated payload length cannot be zero");
		}

		if(PacketReliability::isReliable($reliability)){
			$packet->messageIndex = $stream->getLTriad();
		}

		if(PacketReliability::isSequenced($reliability)){
			$packet->sequenceIndex = $stream->getLTriad();
		}

		if(PacketReliability::isSequencedOrOrdered($reliability)){
			$packet->orderIndex = $stream->getLTriad();
			$packet->orderChannel = $stream->getByte();
		}

		if($hasSplit){
			$splitCount = $stream->getInt();
			$splitID = $stream->getShort();
			$splitIndex = $stream->getInt();
			$packet->splitInfo = new SplitPacketInfo($splitID, $splitIndex, $splitCount);
		}

		$packet->buffer = $stream->get($length);
		return $packet;
	}

	public function toBinary() : string{
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->splitInfo !== null ? self::SPLIT_FLAG : 0)) .
			Binary::writeShort(strlen($this->buffer) << 3) .
			(PacketReliability::isReliable($this->reliability) ? Binary::writeLTriad($this->messageIndex) : "") .
			(PacketReliability::isSequenced($this->reliability) ? Binary::writeLTriad($this->sequenceIndex) : "") .
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "") .
			($this->splitInfo !== null ? Binary::writeInt($this->splitInfo->getTotalPartCount()) . Binary::writeShort($this->splitInfo->getId()) . Binary::writeInt($this->splitInfo->getPartIndex()) : "")
			. $this->buffer;
	}

	public function getTotalLength() : int{
		return
			1 + //reliability
			2 + //length
			(PacketReliability::isReliable($this->reliability) ? 3 : 0) + //message index
			(PacketReliability::isSequenced($this->reliability) ? 3 : 0) + //sequence index
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? 3 + 1 : 0) + //order index (3) + order channel (1)
			($this->splitInfo !== null ? 4 + 2 + 4 : 0) + //split count (4) + split ID (2) + split index (4)
			strlen($this->buffer);
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}
