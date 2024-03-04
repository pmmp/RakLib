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
use function array_push;
use function assert;
use function count;
use function microtime;
use function str_split;
use function strlen;

final class SendReliabilityLayer{
	private const DATAGRAM_MTU_OVERHEAD = 36 + Datagram::HEADER_SIZE; //IP header (20 bytes) + UDP header (8 bytes) + RakNet weird (8 bytes) = 36
	private const MIN_POSSIBLE_PACKET_SIZE_LIMIT = Session::MIN_MTU_SIZE - self::DATAGRAM_MTU_OVERHEAD;
	/**
	 * Delay in seconds before an unacked packet is retransmitted.
	 * TODO: Replace this with dynamic calculation based on roundtrip times (that's a complex task for another time)
	 */
	private const UNACKED_RETRANSMIT_DELAY = 2.0;

	/** @var EncapsulatedPacket[] */
	private array $sendQueue = [];

	private int $splitID = 0;

	private int $sendSeqNumber = 0;

	private int $messageIndex = 0;

	private int $reliableWindowStart;
	private int $reliableWindowEnd;
	/**
	 * @var bool[] message index => acked
	 * @phpstan-var array<int, bool>
	 */
	private array $reliableWindow = [];

	/** @var int[] */
	private array $sendOrderedIndex;
	/** @var int[] */
	private array $sendSequencedIndex;

	/** @var EncapsulatedPacket[] */
	private array $reliableBacklog = [];

	/** @var EncapsulatedPacket[] */
	private array $resendQueue = [];

	/** @var ReliableCacheEntry[] */
	private array $reliableCache = [];

	/** @var int[][] */
	private array $needACK = [];

	/** @phpstan-var int<self::MIN_POSSIBLE_PACKET_SIZE_LIMIT, max> */
	private int $maxDatagramPayloadSize;

	/**
	 * @phpstan-param int<Session::MIN_MTU_SIZE, max> $mtuSize
	 * @phpstan-param \Closure(Datagram) : void       $sendDatagramCallback
	 * @phpstan-param \Closure(int) : void            $onACK
	 */
	public function __construct(
		private int $mtuSize,
		private \Closure $sendDatagramCallback,
		private \Closure $onACK,
		private int $reliableWindowSize = 512,
	){
		$this->sendOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->sendSequencedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);

		$this->maxDatagramPayloadSize = $this->mtuSize - self::DATAGRAM_MTU_OVERHEAD;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = $this->reliableWindowSize;
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
		if(PacketReliability::isReliable($pk->reliability)){
			if($pk->messageIndex === null || $pk->messageIndex < $this->reliableWindowStart){
				throw new \InvalidArgumentException("Cannot send a reliable packet with message index less than the window start ($pk->messageIndex < $this->reliableWindowStart)");
			}
			if($pk->messageIndex >= $this->reliableWindowEnd){
				//If we send this now, the client's reliable window may overflow, causing the packet to need redelivery
				$this->reliableBacklog[$pk->messageIndex] = $pk;
				return;
			}

			$this->reliableWindow[$pk->messageIndex] = false;
		}

		if($pk->identifierACK !== null and $pk->messageIndex !== null){
			$this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
		}

		$length = 0;
		foreach($this->sendQueue as $queued){
			$length += $queued->getTotalLength();
		}

		if($length + $pk->getTotalLength() > $this->maxDatagramPayloadSize){
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

		$maxBufferSize = $this->maxDatagramPayloadSize - $packet->getHeaderLength();

		if(strlen($packet->buffer) > $maxBufferSize){
			$buffers = str_split($packet->buffer, $maxBufferSize - EncapsulatedPacket::SPLIT_INFO_LENGTH);
			$bufferCount = count($buffers);

			$splitID = ++$this->splitID % 65536;
			foreach($buffers as $count => $buffer){
				$pk = new EncapsulatedPacket();
				$pk->splitInfo = new SplitPacketInfo($splitID, $count, $bufferCount);
				$pk->reliability = $packet->reliability;
				$pk->buffer = $buffer;
				$pk->identifierACK = $packet->identifierACK;

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

	private function updateReliableWindow() : void{
		while(
			isset($this->reliableWindow[$this->reliableWindowStart]) && //this messageIndex has been used
			$this->reliableWindow[$this->reliableWindowStart] === true //we received an ack for this messageIndex
		){
			unset($this->reliableWindow[$this->reliableWindowStart]);
			$this->reliableWindowStart++;
			$this->reliableWindowEnd++;
		}
	}

	public function onACK(ACK $packet) : void{
		foreach($packet->packets as $seq){
			if(isset($this->reliableCache[$seq])){
				foreach($this->reliableCache[$seq]->getPackets() as $pk){
					assert($pk->messageIndex !== null && $pk->messageIndex >= $this->reliableWindowStart && $pk->messageIndex < $this->reliableWindowEnd);
					$this->reliableWindow[$pk->messageIndex] = true;
					$this->updateReliableWindow();

					if($pk->identifierACK !== null){
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
				foreach($this->reliableCache[$seq]->getPackets() as $pk){
					$this->resendQueue[] = $pk;
				}
				unset($this->reliableCache[$seq]);
			}
		}
	}

	public function needsUpdate() : bool{
		return (
			count($this->sendQueue) !== 0 or
			count($this->reliableBacklog) !== 0 or
			count($this->resendQueue) !== 0 or
			count($this->reliableCache) !== 0
		);
	}

	public function update() : void{
		$retransmitOlderThan = microtime(true) - self::UNACKED_RETRANSMIT_DELAY;
		foreach($this->reliableCache as $seq => $pk){
			if($pk->getTimestamp() < $retransmitOlderThan){
				//behave as if a NACK was received
				array_push($this->resendQueue, ...$pk->getPackets());
				unset($this->reliableCache[$seq]);
			}else{
				break;
			}
		}

		if(count($this->resendQueue) > 0){
			foreach($this->resendQueue as $pk){
				//resends should always be within the reliable window
				$this->addToQueue($pk, false);
			}
			$this->resendQueue = [];
		}

		if(count($this->reliableBacklog) > 0){
			foreach($this->reliableBacklog as $k => $pk){
				assert($pk->messageIndex !== null && $pk->messageIndex >= $this->reliableWindowStart);
				if($pk->messageIndex >= $this->reliableWindowEnd){
					//we can't send this packet yet, the client's reliable window will drop it
					break;
				}

				$this->addToQueue($pk, false);
				unset($this->reliableBacklog[$k]);
			}
		}

		$this->sendQueue();
	}
}
