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
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use function ceil;
use function chr;
use function strlen;

class EncapsulatedPacket{
	private const RELIABILITY_SHIFT = 5;
	private const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	private const SPLIT_FLAG = 0b00010000;

	public const SPLIT_INFO_LENGTH = 4 + 2 + 4; //split count (4) + split ID (2) + split index (4)

	public PacketReliability $reliability;
	public ?int $messageIndex = null;
	public ?int $sequenceIndex = null;
	public ?int $orderIndex = null;
	public ?int $orderChannel = null;
	public ?SplitPacketInfo $splitInfo = null;
	public string $buffer = "";
	public ?int $identifierACK = null;

	/**
	 * @throws BinaryDataException
	 */
	public static function fromBinary(BinaryStream $stream) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$flags = $stream->getByte();
		$reliability = PacketReliability::tryFrom(($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT);
		if($reliability === null){
			//TODO: we should reject the ACK_RECEIPT types here - they aren't supposed to be sent over the wire
			throw new BinaryDataException("Invalid encapsulated packet reliability");
		}
		$packet->reliability = $reliability;
		$hasSplit = ($flags & self::SPLIT_FLAG) !== 0;

		$length = (int) ceil($stream->getShort() / 8);
		if($length === 0){
			throw new BinaryDataException("Encapsulated payload length cannot be zero");
		}

		if($reliability->isReliable()){
			$packet->messageIndex = $stream->getLTriad();
		}

		if($reliability->isSequenced()){
			$packet->sequenceIndex = $stream->getLTriad();
		}

		if($reliability->isSequencedOrOrdered()){
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
			chr(($this->reliability->value << self::RELIABILITY_SHIFT) | ($this->splitInfo !== null ? self::SPLIT_FLAG : 0)) .
			Binary::writeShort(strlen($this->buffer) << 3) .
			($this->reliability->isReliable() ? Binary::writeLTriad($this->messageIndex) : "") .
			($this->reliability->isSequenced() ? Binary::writeLTriad($this->sequenceIndex) : "") .
			($this->reliability->isSequencedOrOrdered() ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "") .
			($this->splitInfo !== null ? Binary::writeInt($this->splitInfo->getTotalPartCount()) . Binary::writeShort($this->splitInfo->getId()) . Binary::writeInt($this->splitInfo->getPartIndex()) : "")
			. $this->buffer;
	}

	/**
	 * @phpstan-return int<3, 23>
	 */
	public function getHeaderLength() : int{
		return
			1 + //reliability
			2 + //length
			($this->reliability->isReliable() ? 3 : 0) + //message index
			($this->reliability->isSequenced() ? 3 : 0) + //sequence index
			($this->reliability->isSequencedOrOrdered() ? 3 + 1 : 0) + //order index (3) + order channel (1)
			($this->splitInfo !== null ? self::SPLIT_INFO_LENGTH : 0);
	}

	public function getTotalLength() : int{
		return $this->getHeaderLength() + strlen($this->buffer);
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}
