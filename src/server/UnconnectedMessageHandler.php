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
use raklib\protocol\PacketSerializer;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;
use raklib\utils\InternetAddress;
use function get_class;
use function min;
use function ord;
use function strlen;
use function substr;

class UnconnectedMessageHandler{
	/** @var Server */
	private $server;
	/** @var OfflineMessage[]|\SplFixedArray<OfflineMessage> */
	private $packetPool;
	/** @var ProtocolAcceptor */
	private $protocolAcceptor;

	public function __construct(Server $server, ProtocolAcceptor $protocolAcceptor){
		$this->registerPackets();
		$this->server = $server;
		$this->protocolAcceptor = $protocolAcceptor;
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
		$reader = new PacketSerializer($payload);
		$pk->decode($reader);
		if(!$pk->isValid()){
			return false;
		}
		if(!$reader->feof()){
			$remains = substr($reader->getBuffer(), $reader->getOffset());
			$this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . get_class($pk) . " from $address");
		}
		return $this->handle($pk, $address);
	}

	private function handle(OfflineMessage $packet, InternetAddress $address) : bool{
		if($packet instanceof UnconnectedPing){
			$this->server->sendPacket(UnconnectedPong::create($packet->sendPingTime, $this->server->getID(), $this->server->getName()), $address);
		}elseif($packet instanceof OpenConnectionRequest1){
			if(!$this->protocolAcceptor->accepts($packet->protocol)){
				$this->server->sendPacket(IncompatibleProtocolVersion::create($this->protocolAcceptor->getPrimaryVersion(), $this->server->getID()), $address);
				$this->server->getLogger()->notice("Refused connection from $address due to incompatible RakNet protocol version (version $packet->protocol)");
			}else{
				//IP header size (20 bytes) + UDP header size (8 bytes)
				$this->server->sendPacket(OpenConnectionReply1::create($this->server->getID(), false, $packet->mtuSize + 28), $address);
			}
		}elseif($packet instanceof OpenConnectionRequest2){
			if($packet->serverAddress->port === $this->server->getPort() or !$this->server->portChecking){
				if($packet->mtuSize < Session::MIN_MTU_SIZE){
					$this->server->getLogger()->debug("Not creating session for $address due to bad MTU size $packet->mtuSize");
					return true;
				}
				$mtuSize = min($packet->mtuSize, $this->server->getMaxMtuSize()); //Max size, do not allow creating large buffers to fill server memory
				$this->server->sendPacket(OpenConnectionReply2::create($this->server->getID(), $address, $mtuSize, false), $address);
				$this->server->createSession($address, $packet->clientID, $mtuSize);
			}else{
				$this->server->getLogger()->debug("Not creating session for $address due to mismatched port, expected " . $this->server->getPort() . ", got " . $packet->serverAddress->port);
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
			return clone $pk;
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
