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

class Datagram extends Packet{
	public const BITFLAG_VALID = 0x80;
	public const BITFLAG_ACK = 0x40;
	public const BITFLAG_NAK = 0x20; // hasBAndAS for ACKs

	/*
	 * These flags can be set on regular datagrams, but they are useless as per the public version of RakNet
	 * (the receiving client will not use them or pay any attention to them).
	 */
	public const BITFLAG_PACKET_PAIR = 0x10;
	public const BITFLAG_CONTINUOUS_SEND = 0x08;
	public const BITFLAG_NEEDS_B_AND_AS = 0x04;

	public const HEADER_SIZE = 1 + 3; //header flags (1) + sequence number (3)

	public int $headerFlags = 0;
	/** @var EncapsulatedPacket[] */
	public array $packets = [];
	public int $seqNumber;

	protected function encodeHeader(PacketSerializer $out) : void{
		$out->putByte(self::BITFLAG_VALID | $this->headerFlags);
	}

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putLTriad($this->seqNumber);
		foreach($this->packets as $packet){
			$out->put($packet->toBinary());
		}
	}

	/**
	 * @return int
	 */
	public function length(){
		$length = self::HEADER_SIZE;
		foreach($this->packets as $packet){
			$length += $packet->getTotalLength();
		}

		return $length;
	}

	protected function decodeHeader(PacketSerializer $in) : void{
		$this->headerFlags = $in->getByte();
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$this->seqNumber = $in->getLTriad();

		while(!$in->feof()){
			$this->packets[] = EncapsulatedPacket::fromBinary($in);
		}
	}
}
