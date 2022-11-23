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

namespace raklib\generic;

use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;
use raklib\protocol\SplitPacketInfo;
use function array_fill;
use function count;
use function str_split;
use function strlen;
use function time;

final class SendReliabilityLayer{
	/** @var EncapsulatedPacket[] */
	private array $sendQueue = [];

	private int $splitID = 0;

	private int $sendSeqNumber = 0;

	private int $messageIndex = 0;

	/** @var int[] */
	private array $sendOrderedIndex;
	/** @var int[] */
	private array $sendSequencedIndex;

	/** @var ReliableCacheEntry[] */
	private array $resendQueue = [];

	/** @var ReliableCacheEntry[] */
	private array $reliableCache = [];

	/** @var int[][] */
	private array $needACK = [];

	/**
	 * @phpstan-param int<Session::MIN_MTU_SIZE, max> $mtuSize
	 * @phpstan-param \Closure(Datagram) : void $sendDatagramCallback
	 * @phpstan-param \Closure(int) : void      $onACK
	 */
	public function __construct(
		private int $mtuSize,
		private \Closure $sendDatagramCallback,
		private \Closure $onACK
	){
		$this->sendOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->sendSequencedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
	}

	/**
	 * @param EncapsulatedPacket[] $packets
	 */
	private function sendDatagram(array $packets) : void{
		$datagram = new Datagram();
		$datagram->seqNumber = $this->sendSeqNumber++;
		$datagram->packets = $packets;
		($this->sendDatagramCallback)($datagram);

		$resendable = [];
		foreach($datagram->packets as $pk){
			if(PacketReliability::isReliable($pk->reliability)){
				$resendable[] = $pk;
			}
		}
		if(count($resendable) !== 0){
			$this->reliableCache[$datagram->seqNumber] = new ReliableCacheEntry($resendable);
		}
	}

	public function sendQueue() : void{
		if(count($this->sendQueue) > 0){
			$this->sendDatagram($this->sendQueue);
			$this->sendQueue = [];
		}
	}

	private function addToQueue(EncapsulatedPacket $pk, bool $immediate) : void{
		if($pk->identifierACK !== null and $pk->messageIndex !== null){
			$this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
		}

		$length = Datagram::HEADER_SIZE;
		foreach($this->sendQueue as $queued){
			$length += $queued->getTotalLength();
		}

		if($length + $pk->getTotalLength() > $this->mtuSize - 36){ //IP header (20 bytes) + UDP header (8 bytes) + RakNet weird (8 bytes) = 36 bytes
			$this->sendQueue();
		}

		if($pk->identifierACK !== null){
			$this->sendQueue[] = clone $pk;
			$pk->identifierACK = null;
		}else{
			$this->sendQueue[] = $pk;
		}

		if($immediate){
			// Forces pending sends to go out now, rather than waiting to the next update interval
			$this->sendQueue();
		}
	}

	public function addEncapsulatedToQueue(EncapsulatedPacket $packet, bool $immediate = false) : void{
		if($packet->identifierACK !== null){
			$this->needACK[$packet->identifierACK] = [];
		}

		if(PacketReliability::isOrdered($packet->reliability)){
			$packet->orderIndex = $this->sendOrderedIndex[$packet->orderChannel]++;
		}elseif(PacketReliability::isSequenced($packet->reliability)){
			$packet->orderIndex = $this->sendOrderedIndex[$packet->orderChannel]; //sequenced packets don't increment the ordered channel index
			$packet->sequenceIndex = $this->sendSequencedIndex[$packet->orderChannel]++;
		}

		//IP header size (20 bytes) + UDP header size (8 bytes) + RakNet weird (8 bytes) + datagram header size (4 bytes) + max encapsulated packet header size (20 bytes)
		$maxSize = $this->mtuSize - 60;

		if(strlen($packet->buffer) > $maxSize){
			$buffers = str_split($packet->buffer, $maxSize);
			$bufferCount = count($buffers);

			$splitID = ++$this->splitID % 65536;
			foreach($buffers as $count => $buffer){
				$pk = new EncapsulatedPacket();
				$pk->splitInfo = new SplitPacketInfo($splitID, $count, $bufferCount);
				$pk->reliability = $packet->reliability;
				$pk->buffer = $buffer;

				if(PacketReliability::isReliable($pk->reliability)){
					$pk->messageIndex = $this->messageIndex++;
				}

				$pk->sequenceIndex = $packet->sequenceIndex;
				$pk->orderChannel = $packet->orderChannel;
				$pk->orderIndex = $packet->orderIndex;

				$this->addToQueue($pk, true);
			}
		}else{
			if(PacketReliability::isReliable($packet->reliability)){
				$packet->messageIndex = $this->messageIndex++;
			}
			$this->addToQueue($packet, $immediate);
		}
	}

	public function onACK(ACK $packet) : void{
		foreach($packet->packets as $seq){
			if(isset($this->reliableCache[$seq])){
				foreach($this->reliableCache[$seq]->getPackets() as $pk){
					if($pk->identifierACK !== null and $pk->messageIndex !== null){
						unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
						if(count($this->needACK[$pk->identifierACK]) === 0){
							unset($this->needACK[$pk->identifierACK]);
							($this->onACK)($pk->identifierACK);
						}
					}
				}
				unset($this->reliableCache[$seq]);
			}
		}
	}

	public function onNACK(NACK $packet) : void{
		foreach($packet->packets as $seq){
			if(isset($this->reliableCache[$seq])){
				//TODO: group resends if the resulting datagram is below the MTU
				$this->resendQueue[] = $this->reliableCache[$seq];
				unset($this->reliableCache[$seq]);
			}
		}
	}

	public function needsUpdate() : bool{
		return (
			count($this->sendQueue) !== 0 or
			count($this->resendQueue) !== 0 or
			count($this->reliableCache) !== 0
		);
	}

	public function update() : void{
		if(count($this->resendQueue) > 0){
			$limit = 16;
			foreach($this->resendQueue as $k => $pk){
				$this->sendDatagram($pk->getPackets());
				unset($this->resendQueue[$k]);

				if(--$limit <= 0){
					break;
				}
			}

			if(count($this->resendQueue) > ReceiveReliabilityLayer::$WINDOW_SIZE){
				$this->resendQueue = [];
			}
		}

		foreach($this->reliableCache as $seq => $pk){
			if($pk->getTimestamp() < (time() - 8)){
				$this->resendQueue[] = $pk;
				unset($this->reliableCache[$seq]);
			}else{
				break;
			}
		}

		$this->sendQueue();
	}
}
