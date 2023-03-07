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
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;
use function array_fill;
use function assert;
use function count;

final class ReceiveReliabilityLayer{

	/** @var int */
	public static $WINDOW_SIZE = 2048;

	/** @var \Logger */
	private $logger;

	/**
	 * @var \Closure
	 * @phpstan-var \Closure(EncapsulatedPacket) : void
	 */
	private $onRecv;

	/**
	 * @var \Closure
	 * @phpstan-var \Closure(AcknowledgePacket) : void
	 */
	private $sendPacket;

	/** @var int */
	private $windowStart;
	/** @var int */
	private $windowEnd;
	/** @var int */
	private $highestSeqNumber = -1;

	/** @var int[] */
	private $ACKQueue = [];
	/** @var int[] */
	private $NACKQueue = [];

	/** @var int */
	private $reliableWindowStart;
	/** @var int */
	private $reliableWindowEnd;
	/** @var bool[] */
	private $reliableWindow = [];

	/** @var int[] */
	private $receiveOrderedIndex;
	/** @var int[] */
	private $receiveSequencedHighestIndex;
	/** @var EncapsulatedPacket[][] */
	private $receiveOrderedPackets;

	/** @var (EncapsulatedPacket|null)[][] */
	private $splitPackets = [];

	/**
	 * @var int
	 * @phpstan-var positive-int
	 */
	private $maxSplitPacketPartCount;
	/** @var int */
	private $maxConcurrentSplitPackets;

	/**
	 * @phpstan-param positive-int $maxSplitPacketPartCount
	 * @phpstan-param \Closure(EncapsulatedPacket) : void $onRecv
	 * @phpstan-param \Closure(AcknowledgePacket) : void  $sendPacket
	 */
	public function __construct(\Logger $logger, \Closure $onRecv, \Closure $sendPacket, int $maxSplitPacketPartCount = PHP_INT_MAX, int $maxConcurrentSplitPackets = PHP_INT_MAX){
		$this->logger = $logger;
		$this->onRecv = $onRecv;
		$this->sendPacket = $sendPacket;

		$this->windowStart = 0;
		$this->windowEnd = self::$WINDOW_SIZE;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = self::$WINDOW_SIZE;

		$this->receiveOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->receiveSequencedHighestIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);

		$this->receiveOrderedPackets = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, []);

		$this->maxSplitPacketPartCount = $maxSplitPacketPartCount;
		$this->maxConcurrentSplitPackets = $maxConcurrentSplitPackets;
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $pk) : void{
		($this->onRecv)($pk);
	}

	/**
	 * Processes a split part of an encapsulated packet.
	 *
	 * @return null|EncapsulatedPacket Reassembled packet if we have all the parts, null otherwise.
	 */
	private function handleSplit(EncapsulatedPacket $packet) : ?EncapsulatedPacket{
		if($packet->splitInfo === null){
			return $packet;
		}
		$totalParts = $packet->splitInfo->getTotalPartCount();
		$partIndex = $packet->splitInfo->getPartIndex();
		if(
			$totalParts >= $this->maxSplitPacketPartCount or $totalParts < 0 or
			$partIndex >= $totalParts or $partIndex < 0
		){
			$this->logger->debug("Invalid split packet part, too many parts or invalid split index (part index $partIndex, part count $totalParts)");
			return null;
		}

		$splitId = $packet->splitInfo->getId();
		if(!isset($this->splitPackets[$splitId])){
			if(count($this->splitPackets) >= $this->maxConcurrentSplitPackets){
				$this->logger->debug("Ignored split packet part because reached concurrent split packet limit of $this->maxConcurrentSplitPackets");
				return null;
			}
			$this->splitPackets[$splitId] = array_fill(0, $totalParts, null);
		}elseif(count($this->splitPackets[$splitId]) !== $totalParts){
			$this->logger->debug("Wrong split count $totalParts for split packet $splitId, expected " . count($this->splitPackets[$splitId]));
			return null;
		}

		$this->splitPackets[$splitId][$partIndex] = $packet;

		$parts = [];
		foreach($this->splitPackets[$splitId] as $splitIndex => $part){
			if($part === null){
				return null;
			}
			$parts[$splitIndex] = $part;
		}

		//got all parts, reassemble the packet
		$pk = new EncapsulatedPacket();
		$pk->buffer = "";

		$pk->reliability = $packet->reliability;
		$pk->messageIndex = $packet->messageIndex;
		$pk->sequenceIndex = $packet->sequenceIndex;
		$pk->orderIndex = $packet->orderIndex;
		$pk->orderChannel = $packet->orderChannel;

		for($i = 0; $i < $totalParts; ++$i){
			$pk->buffer .= $parts[$i]->buffer;
		}

		unset($this->splitPackets[$splitId]);

		return $pk;
	}

	private function handleEncapsulatedPacket(EncapsulatedPacket $packet) : void{
		if($packet->messageIndex !== null){
			//check for duplicates or out of range
			if($packet->messageIndex < $this->reliableWindowStart or $packet->messageIndex > $this->reliableWindowEnd or isset($this->reliableWindow[$packet->messageIndex])){
				return;
			}

			$this->reliableWindow[$packet->messageIndex] = true;

			if($packet->messageIndex === $this->reliableWindowStart){
				for(; isset($this->reliableWindow[$this->reliableWindowStart]); ++$this->reliableWindowStart){
					unset($this->reliableWindow[$this->reliableWindowStart]);
					++$this->reliableWindowEnd;
				}
			}
		}

		if(($packet = $this->handleSplit($packet)) === null){
			return;
		}

		if(PacketReliability::isSequencedOrOrdered($packet->reliability) and ($packet->orderChannel < 0 or $packet->orderChannel >= PacketReliability::MAX_ORDER_CHANNELS)){
			//TODO: this should result in peer banning
			$this->logger->debug("Invalid packet, bad order channel ($packet->orderChannel)");
			return;
		}

		if(PacketReliability::isSequenced($packet->reliability)){
			if($packet->sequenceIndex < $this->receiveSequencedHighestIndex[$packet->orderChannel] or $packet->orderIndex < $this->receiveOrderedIndex[$packet->orderChannel]){
				//too old sequenced packet, discard it
				return;
			}

			$this->receiveSequencedHighestIndex[$packet->orderChannel] = $packet->sequenceIndex + 1;
			$this->handleEncapsulatedPacketRoute($packet);
		}elseif(PacketReliability::isOrdered($packet->reliability)){
			if($packet->orderIndex === $this->receiveOrderedIndex[$packet->orderChannel]){
				//this is the packet we expected to get next
				//Any ordered packet resets the sequence index to zero, so that sequenced packets older than this ordered
				//one get discarded. Sequenced packets also include (but don't increment) the order index, so a sequenced
				//packet with an order index less than this will get discarded
				$this->receiveSequencedHighestIndex[$packet->orderChannel] = 0;
				$this->receiveOrderedIndex[$packet->orderChannel] = $packet->orderIndex + 1;

				$this->handleEncapsulatedPacketRoute($packet);
				$i = $this->receiveOrderedIndex[$packet->orderChannel];
				for(; isset($this->receiveOrderedPackets[$packet->orderChannel][$i]); ++$i){
					$this->handleEncapsulatedPacketRoute($this->receiveOrderedPackets[$packet->orderChannel][$i]);
					unset($this->receiveOrderedPackets[$packet->orderChannel][$i]);
				}

				$this->receiveOrderedIndex[$packet->orderChannel] = $i;
			}elseif($packet->orderIndex > $this->receiveOrderedIndex[$packet->orderChannel]){
				if(count($this->receiveOrderedPackets[$packet->orderChannel]) >= self::$WINDOW_SIZE){
					//queue overflow for this channel - we should probably disconnect the peer at this point
					return;
				}
				$this->receiveOrderedPackets[$packet->orderChannel][$packet->orderIndex] = $packet;
			}else{
				//duplicate/already received packet
			}
		}else{
			//not ordered or sequenced
			$this->handleEncapsulatedPacketRoute($packet);
		}
	}

	public function onDatagram(Datagram $packet) : void{
		if($packet->seqNumber < $this->windowStart or $packet->seqNumber > $this->windowEnd or isset($this->ACKQueue[$packet->seqNumber])){
			$this->logger->debug("Received duplicate or out-of-window packet (sequence number $packet->seqNumber, window " . $this->windowStart . "-" . $this->windowEnd . ")");
			return;
		}

		unset($this->NACKQueue[$packet->seqNumber]);
		$this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;
		if($this->highestSeqNumber < $packet->seqNumber){
			$this->highestSeqNumber = $packet->seqNumber;
		}

		if($packet->seqNumber === $this->windowStart){
			//got a contiguous packet, shift the receive window
			//this packet might complete a sequence of out-of-order packets, so we incrementally check the indexes
			//to see how far to shift the window, and stop as soon as we either find a gap or have an empty window
			for(; isset($this->ACKQueue[$this->windowStart]); ++$this->windowStart){
				++$this->windowEnd;
			}
		}elseif($packet->seqNumber > $this->windowStart){
			//we got a gap - a later packet arrived before earlier ones did
			//we add the earlier ones to the NACK queue
			//if the missing packets arrive before the end of tick, they'll be removed from the NACK queue
			for($i = $this->windowStart; $i < $packet->seqNumber; ++$i){
				if(!isset($this->ACKQueue[$i])){
					$this->NACKQueue[$i] = $i;
				}
			}
		}else{
			assert(false, "received packet before window start");
		}

		foreach($packet->packets as $pk){
			$this->handleEncapsulatedPacket($pk);
		}
	}

	public function update() : void{
		$diff = $this->highestSeqNumber - $this->windowStart + 1;
		assert($diff >= 0);
		if($diff > 0){
			//Move the receive window to account for packets we either received or are about to NACK
			//we ignore any sequence numbers that we sent NACKs for, because we expect the client to resend them
			//when it gets a NACK for it

			$this->windowStart += $diff;
			$this->windowEnd += $diff;
		}

		if(count($this->ACKQueue) > 0){
			$pk = new ACK();
			$pk->packets = $this->ACKQueue;
			($this->sendPacket)($pk);
			$this->ACKQueue = [];
		}

		if(count($this->NACKQueue) > 0){
			$pk = new NACK();
			$pk->packets = $this->NACKQueue;
			($this->sendPacket)($pk);
			$this->NACKQueue = [];
		}
	}

	public function needsUpdate() : bool{
		return count($this->ACKQueue) !== 0 or count($this->NACKQueue) !== 0;
	}
}
