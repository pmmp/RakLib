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

namespace raklib\server;

use pocketmine\utils\BinaryDataException;
use raklib\generic\DisconnectReason;
use raklib\generic\Session;
use raklib\generic\SocketException;
use raklib\generic\PacketHandlingException;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\Packet;
use raklib\protocol\PacketSerializer;
use raklib\utils\ExceptionTraceCleaner;
use raklib\utils\InternetAddress;
use function asort;
use function bin2hex;
use function count;
use function get_class;
use function microtime;
use function ord;
use function preg_match;
use function strlen;
use function time;
use function time_sleep_until;
use const PHP_INT_MAX;
use const SOCKET_ECONNRESET;

class Server implements ServerInterface{

	public const RAKLIB_TPS = 100;
	public const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	protected int $receiveBytes = 0;
	protected int $sendBytes = 0;

	/** @var ServerSession[] */
	protected array $sessionsByAddress = [];
	/** @var ServerSession[] */
	protected array $sessions = [];

	protected UnconnectedMessageHandler $unconnectedMessageHandler;

	protected string $name = "";

	protected int $packetLimit = 200;

	protected bool $shutdown = false;

	protected int $ticks = 0;

	/** @var int[] string (address) => int (unblock time) */
	protected array $block = [];
	/** @var int[] string (address) => int (number of packets) */
	protected array $ipSec = [];

	/** @var string[] regex filters used to block out unwanted raw packets */
	protected array $rawPacketFilters = [];

	public bool $portChecking = false;

	protected int $nextSessionId = 0;

	/**
	 * @phpstan-param positive-int $recvMaxSplitParts
	 * @phpstan-param positive-int $recvMaxConcurrentSplits
	 */
	public function __construct(
		protected int $serverId,
		protected \Logger $logger,
		protected ServerSocket $socket,
		protected int $maxMtuSize,
		ProtocolAcceptor $protocolAcceptor,
		private ServerEventListener $eventListener,
		private ExceptionTraceCleaner $traceCleaner,
		private int $recvMaxSplitParts = ServerSession::DEFAULT_MAX_SPLIT_PART_COUNT,
		private int $recvMaxConcurrentSplits = ServerSession::DEFAULT_MAX_CONCURRENT_SPLIT_COUNT
	){
		if($maxMtuSize < Session::MIN_MTU_SIZE){
			throw new \InvalidArgumentException("MTU size must be at least " . Session::MIN_MTU_SIZE . ", got $maxMtuSize");
		}
		$this->socket->setBlocking(false);

		$this->unconnectedMessageHandler = new UnconnectedMessageHandler($this, $protocolAcceptor);
	}

	public function getPort() : int{
		return $this->socket->getBindAddress()->getPort();
	}

	public function getMaxMtuSize() : int{
		return $this->maxMtuSize;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	/**
	 * Disconnects all sessions and blocks until everything has been shut down properly.
	 */
	public function waitShutdown() : void{
		$this->shutdown = true;

		foreach($this->sessions as $session){
			$session->initiateDisconnect(DisconnectReason::SERVER_SHUTDOWN);
		}

		while(count($this->sessions) > 0){
			$start = microtime(true);

			while($this->receivePacket()){
				//NOOP
			}
			$this->tick();

			$time = microtime(true) - $start;
			if($time < self::RAKLIB_TIME_PER_TICK && count($this->sessions) > 0){
				@time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
			}
		}

		$this->socket->close();
		$this->logger->debug("Graceful shutdown complete");
	}

	/**
	 * Ticks sessions, updates bandwidth stats and IP bans, and other heartbeat tasks.
	 * This should be called once per 10ms. It must be called in regular intervals.
	 * @see self::RAKLIB_TIME_PER_TICK
	 */
	public function tick() : void{
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
			if($session->isFullyDisconnected()){
				$this->removeSessionInternal($session);
			}
		}

		$this->ipSec = [];

		if(!$this->shutdown and ($this->ticks % self::RAKLIB_TPS) === 0){
			if($this->sendBytes > 0 or $this->receiveBytes > 0){
				$this->eventListener->onBandwidthStatsUpdate($this->sendBytes, $this->receiveBytes);
				$this->sendBytes = 0;
				$this->receiveBytes = 0;
			}

			if(count($this->block) > 0){
				asort($this->block);
				$now = time();
				foreach($this->block as $address => $timeout){
					if($timeout <= $now){
						unset($this->block[$address]);
					}else{
						break;
					}
				}
			}
		}

		++$this->ticks;
	}

	/**
	 * Reads a packet from the socket and processes it.
	 *
	 * This should be called in a loop until it returns false. However, it may be desirable to break out of the loop
	 * to do other tasks even if there are packets left to process, to ensure high traffic doesn't starve other tasks.
	 *
	 * @phpstan-impure
	 */
	public function receivePacket() : bool{
		try{
			$buffer = $this->socket->readPacket($addressIp, $addressPort);
		}catch(SocketException $e){
			$error = $e->getCode();
			if($error === SOCKET_ECONNRESET){ //client disconnected improperly, maybe crash or lost connection
				return true;
			}

			$this->logger->debug($e->getMessage());
			return false;
		}
		if($buffer === null){
			return false; //no data
		}
		$len = strlen($buffer);

		$this->receiveBytes += $len;
		if(isset($this->block[$addressIp])){
			return true;
		}

		if(isset($this->ipSec[$addressIp])){
			if(++$this->ipSec[$addressIp] >= $this->packetLimit){
				$this->blockAddress($addressIp);
				return true;
			}
		}else{
			$this->ipSec[$addressIp] = 1;
		}

		if($len < 1){
			return true;
		}

		$address = new InternetAddress($addressIp, $addressPort, $this->socket->getBindAddress()->getVersion());
		try{
			$session = $this->getSessionByAddress($address);
			if($session !== null){
				$header = ord($buffer[0]);
				if(($header & Datagram::BITFLAG_VALID) !== 0){
					if(($header & Datagram::BITFLAG_ACK) !== 0){
						$packet = new ACK();
					}elseif(($header & Datagram::BITFLAG_NAK) !== 0){
						$packet = new NACK();
					}else{
						$packet = new Datagram();
					}
					$packet->decode(new PacketSerializer($buffer));
					try{
						$session->handlePacket($packet);
					}catch(PacketHandlingException $e){
						$session->getLogger()->error("Error receiving packet: " . $e->getMessage());
						$session->forciblyDisconnect($e->getDisconnectReason());
					}
					return true;
				}elseif($session->isConnected()){
					//allows unconnected packets if the session is stuck in DISCONNECTING state, useful if the client
					//didn't disconnect properly for some reason (e.g. crash)
					$this->logger->debug("Ignored unconnected packet from $address due to session already opened (0x" . bin2hex($buffer[0]) . ")");
					return true;
				}
			}

			if(!$this->shutdown){
				if(!($handled = $this->unconnectedMessageHandler->handleRaw($buffer, $address))){
					foreach($this->rawPacketFilters as $pattern){
						if(preg_match($pattern, $buffer) > 0){
							$handled = true;
							$this->eventListener->onRawPacketReceive($address->getIp(), $address->getPort(), $buffer);
							break;
						}
					}
				}

				if(!$handled){
					$this->logger->debug("Ignored packet from $address due to no session opened (0x" . bin2hex($buffer[0]) . ")");
				}
			}
		}catch(BinaryDataException $e){
			$logFn = function() use ($address, $e, $buffer) : void{
				$this->logger->debug("Packet from $address (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer));
				$this->logger->debug(get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
				foreach($this->traceCleaner->getTrace(0, $e->getTrace()) as $line){
					$this->logger->debug($line);
				}
				$this->logger->error("Bad packet from $address: " . $e->getMessage());
			};
			if($this->logger instanceof \BufferedLogger){
				$this->logger->buffer($logFn);
			}else{
				$logFn();
			}
			$this->blockAddress($address->getIp(), 5);
		}

		return true;
	}

	public function sendPacket(Packet $packet, InternetAddress $address) : void{
		$out = new PacketSerializer(); //TODO: reusable streams to reduce allocations
		$packet->encode($out);
		try{
			$this->sendBytes += $this->socket->writePacket($out->getBuffer(), $address->getIp(), $address->getPort());
		}catch(SocketException $e){
			$this->logger->debug($e->getMessage());
		}
	}

	public function getEventListener() : ServerEventListener{
		return $this->eventListener;
	}

	public function sendEncapsulated(int $sessionId, EncapsulatedPacket $packet, bool $immediate = false) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null and $session->isConnected()){
			$session->addEncapsulatedToQueue($packet, $immediate);
		}
	}

	public function sendRaw(string $address, int $port, string $payload) : void{
		try{
			$this->socket->writePacket($payload, $address, $port);
		}catch(SocketException $e){
			$this->logger->debug($e->getMessage());
		}
	}

	public function closeSession(int $sessionId) : void{
		if(isset($this->sessions[$sessionId])){
			$this->sessions[$sessionId]->initiateDisconnect(DisconnectReason::SERVER_DISCONNECT);
		}
	}

	public function setName(string $name) : void{
		$this->name = $name;
	}

	public function setPortCheck(bool $value) : void{
		$this->portChecking = $value;
	}

	public function setPacketsPerTickLimit(int $limit) : void{
		$this->packetLimit = $limit;
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$final = time() + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				$this->logger->notice("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->block[$address]);
		$this->logger->debug("Unblocked $address");
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->rawPacketFilters[] = $regex;
	}

	public function getSessionByAddress(InternetAddress $address) : ?ServerSession{
		return $this->sessionsByAddress[$address->toString()] ?? null;
	}

	public function sessionExists(InternetAddress $address) : bool{
		return isset($this->sessionsByAddress[$address->toString()]);
	}

	public function createSession(InternetAddress $address, int $clientId, int $mtuSize) : ServerSession{
		$existingSession = $this->sessionsByAddress[$address->toString()] ?? null;
		if($existingSession !== null){
			$existingSession->forciblyDisconnect(DisconnectReason::CLIENT_RECONNECT);
			$this->removeSessionInternal($existingSession);
		}

		$this->checkSessions();

		while(isset($this->sessions[$this->nextSessionId])){
			$this->nextSessionId++;
			$this->nextSessionId &= 0x7fffffff; //we don't expect more than 2 billion simultaneous connections, and this fits in 4 bytes
		}

		$session = new ServerSession($this, $this->logger, clone $address, $clientId, $mtuSize, $this->nextSessionId, $this->recvMaxSplitParts, $this->recvMaxConcurrentSplits);
		$this->sessionsByAddress[$address->toString()] = $session;
		$this->sessions[$this->nextSessionId] = $session;
		$this->logger->debug("Created session for $address with MTU size $mtuSize");

		return $session;
	}

	private function removeSessionInternal(ServerSession $session) : void{
		unset($this->sessionsByAddress[$session->getAddress()->toString()], $this->sessions[$session->getInternalId()]);
	}

	public function openSession(ServerSession $session) : void{
		$address = $session->getAddress();
		$this->eventListener->onClientConnect($session->getInternalId(), $address->getIp(), $address->getPort(), $session->getID());
	}

	private function checkSessions() : void{
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $sessionId => $session){
				if($session->isTemporary()){
					$this->removeSessionInternal($session);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function getName() : string{
		return $this->name;
	}

	public function getID() : int{
		return $this->serverId;
	}
}
