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

namespace raklib\server;

use pocketmine\utils\BinaryDataException;
use raklib\protocol\IncompatibleProtocolVersion;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;
use raklib\utils\InternetAddress;
use function abs;
use function get_class;
use function min;
use function ord;
use function strlen;
use function substr;

class OfflineMessageHandler{
	/** @var SessionManager */
	private $sessionManager;
	/** @var OfflineMessage[]|\SplFixedArray */
	private $packetPool;

	public function __construct(SessionManager $manager){
		$this->registerPackets();
		$this->sessionManager = $manager;
	}

	/**
	 * @param string          $payload
	 * @param InternetAddress $address
	 *
	 * @return bool
	 * @throws BinaryDataException
	 */
	public function handleRaw(string $payload, InternetAddress $address) : bool{
		if($payload === ""){
			return false;
		}
		$pk = $this->getPacketFromPool($payload);
		if($pk === null){
			return false;
		}
		$pk->decode();
		if(!$pk->isValid()){
			return false;
		}
		if(!$pk->feof()){
			$remains = substr($pk->getBuffer(), $pk->getOffset());
			$this->sessionManager->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . get_class($pk) . " from $address");
		}
		return $this->handle($pk, $address);
	}

	public function handle(OfflineMessage $packet, InternetAddress $address) : bool{
		if($packet instanceof UnconnectedPing){
			$pk = new UnconnectedPong();
			$pk->serverId = $this->sessionManager->getID();
			$pk->sendPingTime = $packet->sendPingTime;
			$pk->serverName = $this->sessionManager->getName();
			$this->sessionManager->sendPacket($pk, $address);
		}elseif($packet instanceof OpenConnectionRequest1){
			$serverProtocol = $this->sessionManager->getProtocolVersion();
			if($packet->protocol !== $serverProtocol){
				$pk = new IncompatibleProtocolVersion();
				$pk->protocolVersion = $serverProtocol;
				$pk->serverId = $this->sessionManager->getID();
				$this->sessionManager->sendPacket($pk, $address);
				$this->sessionManager->getLogger()->notice("Refused connection from $address due to incompatible RakNet protocol version (expected $serverProtocol, got $packet->protocol)");
			}else{
				$pk = new OpenConnectionReply1();
				$pk->mtuSize = $packet->mtuSize + 28; //IP header size (20 bytes) + UDP header size (8 bytes)
				$pk->serverID = $this->sessionManager->getID();
				$this->sessionManager->sendPacket($pk, $address);
			}
		}elseif($packet instanceof OpenConnectionRequest2){
			if($packet->serverAddress->port === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
				$mtuSize = min(abs($packet->mtuSize), $this->sessionManager->getMaxMtuSize()); //Max size, do not allow creating large buffers to fill server memory
				$pk = new OpenConnectionReply2();
				$pk->mtuSize = $mtuSize;
				$pk->serverID = $this->sessionManager->getID();
				$pk->clientAddress = $address;
				$this->sessionManager->sendPacket($pk, $address);
				$this->sessionManager->createSession($address, $packet->clientID, $mtuSize);
			}else{
				$this->sessionManager->getLogger()->debug("Not creating session for $address due to mismatched port, expected " . $this->sessionManager->getPort() . ", got " . $packet->serverAddress->port);
			}
		}else{
			return false;
		}

		return true;
	}

	/**
	 * @param int    $id
	 * @param string $class
	 */
	private function registerPacket(int $id, string $class) : void{
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param string $buffer
	 *
	 * @return OfflineMessage|null
	 */
	public function getPacketFromPool(string $buffer) : ?OfflineMessage{
		$pk = $this->packetPool[ord($buffer[0])];
		if($pk !== null){
			$pk = clone $pk;
			$pk->buffer = $buffer;
			return $pk;
		}

		return null;
	}

	private function registerPackets() : void{
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionRequest1::$ID, OpenConnectionRequest1::class);
		$this->registerPacket(OpenConnectionRequest2::$ID, OpenConnectionRequest2::class);
	}

}
