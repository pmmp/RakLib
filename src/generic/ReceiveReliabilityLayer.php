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

	public static int $WINDOW_SIZE = 2048;

	private int $windowStart;
	private int $windowEnd;
	private int $highestSeqNumber = -1;

	/** @var int[] */
	private array $ACKQueue = [];
	/** @var int[] */
	private array $NACKQueue = [];

	private int $reliableWindowStart;
	private int $reliableWindowEnd;
	/** @var bool[] */
	private array $reliableWindow = [];

	/** @var int[] */
	private array $receiveOrderedIndex;
	/** @var int[] */
	private array $receiveSequencedHighestIndex;
	/** @var EncapsulatedPacket[][] */
	private array $receiveOrderedPackets;

	/** @var (EncapsulatedPacket|null)[][] */
	private array $splitPackets = [];

	/**
	 * @phpstan-param positive-int $maxSplitPacketPartCount
	 * @phpstan-param \Closure(EncapsulatedPacket) : void $onRecv
	 * @phpstan-param \Closure(AcknowledgePacket) : void  $sendPacket
	 */
	public function __construct(
		private \Logger $logger,
		private \Closure $onRecv,
		private \Closure $sendPacket,
		private int $maxSplitPacketPartCount = PHP_INT_MAX,
		private int $maxConcurrentSplitPackets = PHP_INT_MAX
	){
		$this->windowStart = 0;
		$this->windowEnd = self::$WINDOW_SIZE;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = self::$WINDOW_SIZE;

		$this->receiveOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->receiveSequencedHighestIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);

		$this->receiveOrderedPackets = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, []);
	}

	private function handleEncapsulatedPacketRoute(EncapsulatedPacket $pk) : void{
		($this->onRecv)($pk);
	}

	/**
	 * Processes a split part of an encapsulated packet.
	 * If an error occurs (limit exceeded, inconsistent header, etc.) a PacketHandlingException is thrown.
	 *
	 * An error processing a split packet means we can't fulfill reliability promises, which at best would lead to some
	 * packets not arriving, and in the worst case (reliable-ordered) cause no more packets to be processed.
	 * Therefore, the owning session MUST disconnect the peer if this exception is thrown.
	 *
	 * @return null|EncapsulatedPacket Reassembled packet if we have all the parts, null otherwise.
	 * @throws PacketHandlingException if there was a problem with processing the split packet.
	 */
	private function handleSplit(EncapsulatedPacket $packet) : ?EncapsulatedPacket{
		if($packet->splitInfo === null){
			return $packet;
		}
		$totalParts = $packet->splitInfo->getTotalPartCount();
		$partIndex = $packet->splitInfo->getPartIndex();
		if($totalParts >= $this->maxSplitPacketPartCount || $totalParts < 0){
			throw new PacketHandlingException("Invalid split packet part count ($totalParts)", DisconnectReason::SPLIT_PACKET_TOO_LARGE);
		}
		if($partIndex >= $totalParts || $partIndex < 0){
			throw new PacketHandlingException("Invalid split packet part index (part index $partIndex, part count $totalParts)", DisconnectReason::SPLIT_PACKET_INVALID_PART_INDEX);
		}

		$splitId = $packet->splitInfo->getId();
		if(!isset($this->splitPackets[$splitId])){
			if(count($this->splitPackets) >= $this->maxConcurrentSplitPackets){
				throw new PacketHandlingException("Exceeded concurrent split packet reassembly limit of $this->maxConcurrentSplitPackets", DisconnectReason::SPLIT_PACKET_TOO_MANY_CONCURRENT);
			}
			$this->splitPackets[$splitId] = array_fill(0, $totalParts, null);
		}elseif(count($this->splitPackets[$splitId]) !== $totalParts){
			throw new PacketHandlingException("Wrong split count $totalParts for split packet $splitId, expected " . count($this->splitPackets[$splitId]), DisconnectReason::SPLIT_PACKET_INCONSISTENT_HEADER);
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

	/**
	 * @throws PacketHandlingException
	 */
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
			assert($packet->orderChannel !== null && $packet->sequenceIndex !== null, 'These should have been set during decode');
			if($packet->sequenceIndex < $this->receiveSequencedHighestIndex[$packet->orderChannel] or $packet->orderIndex < $this->receiveOrderedIndex[$packet->orderChannel]){
				//too old sequenced packet, discard it
				return;
			}

			$this->receiveSequencedHighestIndex[$packet->orderChannel] = $packet->sequenceIndex + 1;
			$this->handleEncapsulatedPacketRoute($packet);
		}elseif(PacketReliability::isOrdered($packet->reliability)){
			$orderChannel = $packet->orderChannel;
			assert($orderChannel !== null, 'This should have been set during decode');
			if($packet->orderIndex === $this->receiveOrderedIndex[$orderChannel]){
				//this is the packet we expected to get next
				//Any ordered packet resets the sequence index to zero, so that sequenced packets older than this ordered
				//one get discarded. Sequenced packets also include (but don't increment) the order index, so a sequenced
				//packet with an order index less than this will get discarded
				$this->receiveSequencedHighestIndex[$orderChannel] = 0;
				$this->receiveOrderedIndex[$orderChannel] = $packet->orderIndex + 1;

				$this->handleEncapsulatedPacketRoute($packet);
				$i = $this->receiveOrderedIndex[$orderChannel];
				for(; isset($this->receiveOrderedPackets[$orderChannel][$i]); ++$i){
					$this->handleEncapsulatedPacketRoute($this->receiveOrderedPackets[$orderChannel][$i]);
					unset($this->receiveOrderedPackets[$orderChannel][$i]);
				}

				$this->receiveOrderedIndex[$orderChannel] = $i;
			}elseif($packet->orderIndex > $this->receiveOrderedIndex[$orderChannel]){
				if(count($this->receiveOrderedPackets[$orderChannel]) >= self::$WINDOW_SIZE){
					//queue overflow for this channel - we should probably disconnect the peer at this point
					return;
				}
				$this->receiveOrderedPackets[$orderChannel][$packet->orderIndex] = $packet;
			}else{
				//duplicate/already received packet
			}
		}else{
			//not ordered or sequenced
			$this->handleEncapsulatedPacketRoute($packet);
		}
	}

	/**
	 * @throws PacketHandlingException
	 */
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
