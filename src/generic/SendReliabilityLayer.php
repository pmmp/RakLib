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

namespace raklib\generic;

use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;
use raklib\protocol\SplitPacketInfo;
use function array_fill;
use function assert;
use function count;
use function microtime;
use function str_split;
use function strlen;
use function time;

final class SendReliabilityLayer{

	/**
	 * @var \Closure
	 * @phpstan-var \Closure(Datagram) : void
	 */
	private $sendDatagramCallback;
	/**
	 * @var \Closure
	 * @phpstan-var \Closure(int) : void
	 */
	private $onACK;

	/** @var int */
	private $mtuSize;

	/** @var Datagram */
	private $sendQueue;

	/** @var int */
	private $splitID = 0;

	/** @var int */
	private $sendSeqNumber = 0;

	/** @var int */
	private $messageIndex = 0;

	/** @var int[] */
	private $sendOrderedIndex;
	/** @var int[] */
	private $sendSequencedIndex;

	/** @var Datagram[] */
	private $packetToSend = [];

	/** @var Datagram[] */
	private $recoveryQueue = [];

	/** @var int[][] */
	private $needACK = [];

	/**
	 * @phpstan-param \Closure(Datagram) : void $sendDatagram
	 * @phpstan-param \Closure(int) : void      $onACK
	 */
	public function __construct(int $mtuSize, \Closure $sendDatagram, \Closure $onACK){
		$this->mtuSize = $mtuSize;
		$this->sendDatagramCallback = $sendDatagram;
		$this->onACK = $onACK;

		$this->sendQueue = new Datagram();

		$this->sendOrderedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
		$this->sendSequencedIndex = array_fill(0, PacketReliability::MAX_ORDER_CHANNELS, 0);
	}

	private function sendDatagram(Datagram $datagram) : void{
		if($datagram->seqNumber !== null){
			unset($this->recoveryQueue[$datagram->seqNumber]);
		}
		$datagram->seqNumber = $this->sendSeqNumber++;
		$datagram->sendTime = microtime(true);
		$this->recoveryQueue[$datagram->seqNumber] = $datagram;
		($this->sendDatagramCallback)($datagram);
	}

	public function sendQueue() : void{
		if(count($this->sendQueue->packets) > 0){
			$this->sendDatagram($this->sendQueue);
			$this->sendQueue = new Datagram();
		}
	}

	private function addToQueue(EncapsulatedPacket $pk, bool $immediate) : void{
		if($pk->identifierACK !== null and $pk->messageIndex !== null){
			$this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
		}

		$length = $this->sendQueue->length();
		if($length + $pk->getTotalLength() > $this->mtuSize - 36){ //IP header (20 bytes) + UDP header (8 bytes) + RakNet weird (8 bytes) = 36 bytes
			$this->sendQueue();
		}

		if($pk->identifierACK !== null){
			$this->sendQueue->packets[] = clone $pk;
			$pk->identifierACK = null;
		}else{
			$this->sendQueue->packets[] = $pk;
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
			assert($buffers !== false);
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
			$this->addToQueue($packet, false);
		}
	}

	public function onACK(ACK $packet) : void{
		$packet->decode();
		foreach($packet->packets as $seq){
			if(isset($this->recoveryQueue[$seq])){
				foreach($this->recoveryQueue[$seq]->packets as $pk){
					if($pk->identifierACK !== null and $pk->messageIndex !== null){
						unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
						if(count($this->needACK[$pk->identifierACK]) === 0){
							unset($this->needACK[$pk->identifierACK]);
							($this->onACK)($pk->identifierACK);
						}
					}
				}
				unset($this->recoveryQueue[$seq]);
			}
		}
	}

	public function onNACK(NACK $packet) : void{
		$packet->decode();
		foreach($packet->packets as $seq){
			if(isset($this->recoveryQueue[$seq])){
				$this->packetToSend[] = $this->recoveryQueue[$seq];
				unset($this->recoveryQueue[$seq]);
			}
		}
	}

	public function needsUpdate() : bool{
		return (
			count($this->sendQueue->packets) !== 0 or
			count($this->packetToSend) !== 0 or
			count($this->recoveryQueue) !== 0
		);
	}

	public function update() : void{
		if(count($this->packetToSend) > 0){
			$limit = 16;
			foreach($this->packetToSend as $k => $pk){
				$this->sendDatagram($pk);
				unset($this->packetToSend[$k]);

				if(--$limit <= 0){
					break;
				}
			}

			if(count($this->packetToSend) > ReceiveReliabilityLayer::$WINDOW_SIZE){
				$this->packetToSend = [];
			}
		}

		foreach($this->recoveryQueue as $seq => $pk){
			if($pk->sendTime < (time() - 8)){
				$this->packetToSend[] = $pk;
				unset($this->recoveryQueue[$seq]);
			}else{
				break;
			}
		}

		$this->sendQueue();
	}
}
