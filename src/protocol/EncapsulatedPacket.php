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
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use function ceil;
use function strlen;

class EncapsulatedPacket{
	private const RELIABILITY_SHIFT = 5;
	private const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	private const SPLIT_FLAG = 0b00010000;

	public const SPLIT_INFO_LENGTH = 4 + 2 + 4; //split count (4) + split ID (2) + split index (4)

	public int $reliability;
	public ?int $messageIndex = null;
	public ?int $sequenceIndex = null;
	public ?int $orderIndex = null;
	public ?int $orderChannel = null;
	public ?SplitPacketInfo $splitInfo = null;
	public string $buffer = "";
	public ?int $identifierACK = null;

	/**
	 * @throws DataDecodeException
	 */
	public static function fromBinary(ByteBufferReader $stream) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$flags = Byte::readUnsigned($stream);
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$hasSplit = ($flags & self::SPLIT_FLAG) !== 0;

		$length = (int) ceil(BE::readUnsignedShort($stream) / 8);
		if($length === 0){
			throw new DataDecodeException("Encapsulated payload length cannot be zero");
		}

		if(PacketReliability::isReliable($reliability)){
			$packet->messageIndex = LE::readUnsignedTriad($stream);
		}

		if(PacketReliability::isSequenced($reliability)){
			$packet->sequenceIndex = LE::readUnsignedTriad($stream);
		}

		if(PacketReliability::isSequencedOrOrdered($reliability)){
			$packet->orderIndex = LE::readUnsignedTriad($stream);
			$packet->orderChannel = Byte::readUnsigned($stream);
		}

		if($hasSplit){
			$splitCount = BE::readUnsignedInt($stream);
			$splitID = BE::readUnsignedShort($stream);
			$splitIndex = BE::readUnsignedInt($stream);
			$packet->splitInfo = new SplitPacketInfo($splitID, $splitIndex, $splitCount);
		}

		$packet->buffer = $stream->readByteArray($length);
		return $packet;
	}

	public function toBinary(ByteBufferWriter $out) : void{
		Byte::writeUnsigned($out, ($this->reliability << self::RELIABILITY_SHIFT) | ($this->splitInfo !== null ? self::SPLIT_FLAG : 0));

		BE::writeUnsignedShort($out, strlen($this->buffer) << 3);
		if(PacketReliability::isReliable($this->reliability)){
			LE::writeUnsignedTriad($out, $this->messageIndex);
		}
		if(PacketReliability::isSequenced($this->reliability)){
			LE::writeUnsignedTriad($out, $this->sequenceIndex);
		}
		if(PacketReliability::isSequencedOrOrdered($this->reliability)){
			LE::writeUnsignedTriad($out, $this->orderIndex);
			Byte::writeUnsigned($out, $this->orderChannel);
		}
		if($this->splitInfo !== null){
			BE::writeUnsignedInt($out, $this->splitInfo->getTotalPartCount());
			BE::writeUnsignedShort($out, $this->splitInfo->getId());
			BE::writeUnsignedInt($out, $this->splitInfo->getPartIndex());
		}
		$out->writeByteArray($this->buffer);
	}

	/**
	 * @phpstan-return int<3, 23>
	 */
	public function getHeaderLength() : int{
		return
			1 + //reliability
			2 + //length
			(PacketReliability::isReliable($this->reliability) ? 3 : 0) + //message index
			(PacketReliability::isSequenced($this->reliability) ? 3 : 0) + //sequence index
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? 3 + 1 : 0) + //order index (3) + order channel (1)
			($this->splitInfo !== null ? self::SPLIT_INFO_LENGTH : 0);
	}

	public function getTotalLength() : int{
		return $this->getHeaderLength() + strlen($this->buffer);
	}
}
