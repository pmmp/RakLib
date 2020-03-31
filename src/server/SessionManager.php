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
use raklib\generic\Socket;
use raklib\generic\SocketException;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\Packet;
use raklib\RakLib;
use raklib\utils\ExceptionTraceCleaner;
use raklib\utils\InternetAddress;
use function asort;
use function bin2hex;
use function count;
use function get_class;
use function max;
use function microtime;
use function ord;
use function preg_match;
use function serialize;
use function strlen;
use function time;
use function time_sleep_until;
use const PHP_INT_MAX;
use const SOCKET_ECONNRESET;

class SessionManager implements ServerInterface{

	private const RAKLIB_TPS = 100;
	private const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	/** @var Socket */
	protected $socket;

	/** @var \Logger */
	protected $logger;

	/** @var int */
	protected $serverId;
	/** @var int */
	protected $protocolVersion;

	/** @var int */
	protected $receiveBytes = 0;
	/** @var int */
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessionsByAddress = [];
	/** @var Session[] */
	protected $sessions = [];

	/** @var OfflineMessageHandler */
	protected $offlineMessageHandler;
	/** @var string */
	protected $name = "";

	/** @var int */
	protected $packetLimit = 200;

	/** @var bool */
	protected $shutdown = false;

	/** @var int */
	protected $ticks = 0;
	/** @var float */
	protected $lastMeasure;

	/** @var int[] string (address) => int (unblock time) */
	protected $block = [];
	/** @var int[] string (address) => int (number of packets) */
	protected $ipSec = [];

	/** @var string[] regex filters used to block out unwanted raw packets */
	protected $rawPacketFilters = [];

	/** @var bool */
	public $portChecking = false;

	/** @var int */
	protected $startTimeMS;

	/** @var int */
	protected $maxMtuSize;

	/** @var InternetAddress */
	protected $reusableAddress;

	/** @var int */
	protected $nextSessionId = 0;

	/** @var ServerEventSource */
	private $eventSource;
	/** @var ServerEventListener */
	private $eventListener;

	/** @var ExceptionTraceCleaner */
	private $traceCleaner;

	public function __construct(int $serverId, \Logger $logger, Socket $socket, int $maxMtuSize, int $protocolVersion, ServerEventSource $eventSource, ServerEventListener $eventListener, ExceptionTraceCleaner $traceCleaner){
		$this->serverId = $serverId;
		$this->logger = $logger;
		$this->socket = $socket;
		$this->maxMtuSize = $maxMtuSize;
		$this->protocolVersion = $protocolVersion;
		$this->eventSource = $eventSource;
		$this->eventListener = $eventListener;
		$this->traceCleaner = $traceCleaner;

		$this->startTimeMS = (int) (microtime(true) * 1000);

		$this->offlineMessageHandler = new OfflineMessageHandler($this);

		$this->reusableAddress = clone $this->socket->getBindAddress();
	}

	/**
	 * Returns the time in milliseconds since server start.
	 * @return int
	 */
	public function getRakNetTimeMS() : int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	public function getPort() : int{
		return $this->socket->getBindAddress()->port;
	}

	public function getMaxMtuSize() : int{
		return $this->maxMtuSize;
	}

	public function getProtocolVersion() : int{
		return $this->protocolVersion;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	public function run() : void{
		$this->tickProcessor();
	}

	private function tickProcessor() : void{
		$this->lastMeasure = microtime(true);

		while(!$this->shutdown){
			$start = microtime(true);

			/*
			 * The below code is designed to allow co-op between sending and receiving to avoid slowing down either one
			 * when high traffic is coming either way. Yielding will occur after 100 messages.
			 */
			do{
				$stream = true;
				for($i = 0; $i < 100 && $stream && !$this->shutdown; ++$i){
					$stream = $this->eventSource->process($this);
				}

				$socket = true;
				for($i = 0; $i < 100 && $socket && !$this->shutdown; ++$i){
					$socket = $this->receivePacket();
				}
			}while(!$this->shutdown && ($stream || $socket));

			$this->tick();

			$time = microtime(true) - $start;
			if($time < self::RAKLIB_TIME_PER_TICK){
				@time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
			}
		}
	}

	private function tick() : void{
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		$this->ipSec = [];

		if(($this->ticks % self::RAKLIB_TPS) === 0){
			if($this->sendBytes > 0 or $this->receiveBytes > 0){
				$diff = max(0.005, $time - $this->lastMeasure);
				$this->eventListener->handleOption("bandwidth", serialize([
					"up" => $this->sendBytes / $diff,
					"down" => $this->receiveBytes / $diff
				]));
				$this->sendBytes = 0;
				$this->receiveBytes = 0;
			}
			$this->lastMeasure = $time;

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


	private function receivePacket() : bool{
		$address = $this->reusableAddress;

		try{
			$buffer = $this->socket->readPacket($address->ip, $address->port);
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
		if(isset($this->block[$address->ip])){
			return true;
		}

		if(isset($this->ipSec[$address->ip])){
			if(++$this->ipSec[$address->ip] >= $this->packetLimit){
				$this->blockAddress($address->ip);
				return true;
			}
		}else{
			$this->ipSec[$address->ip] = 1;
		}

		if($len < 1){
			return true;
		}

		try{
			$session = $this->getSessionByAddress($address);
			if($session !== null){
				$header = ord($buffer[0]);
				if(($header & Datagram::BITFLAG_VALID) !== 0){
					if(($header & Datagram::BITFLAG_ACK) !== 0){
						$session->handlePacket(new ACK($buffer));
					}elseif(($header & Datagram::BITFLAG_NAK) !== 0){
						$session->handlePacket(new NACK($buffer));
					}else{
						$session->handlePacket(new Datagram($buffer));
					}
				}else{
					$this->logger->debug("Ignored unconnected packet from $address due to session already opened (0x" . bin2hex($buffer[0]) . ")");
				}
			}elseif(!$this->offlineMessageHandler->handleRaw($buffer, $address)){
				$handled = false;
				foreach($this->rawPacketFilters as $pattern){
					if(preg_match($pattern, $buffer) > 0){
						$handled = true;
						$this->eventListener->handleRaw($address->ip, $address->port, $buffer);
						break;
					}
				}

				if(!$handled){
					$this->logger->debug("Ignored packet from $address due to no session opened (0x" . bin2hex($buffer[0]) . ")");
				}
			}
		}catch(BinaryDataException $e){
			$logFn = function() use($address, $e, $buffer): void{
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
			$this->blockAddress($address->ip, 5);
		}

		return true;
	}

	public function sendPacket(Packet $packet, InternetAddress $address) : void{
		$packet->encode();
		try{
			$this->sendBytes += $this->socket->writePacket($packet->getBuffer(), $address->ip, $address->port);
		}catch(SocketException $e){
			$this->logger->debug($e->getMessage());
		}
	}

	public function getEventListener() : ServerEventListener{
		return $this->eventListener;
	}

	public function sendEncapsulated(int $sessionId, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null and $session->isConnected()){
			$session->addEncapsulatedToQueue($packet, $flags);
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
			$this->sessions[$sessionId]->flagForDisconnection();
		}
	}

	/**
	 * TODO: replace this crap with a proper API
	 */
	public function setOption(string $name, string $value) : void{
		switch($name){
			case "name":
				$this->name = $value;
				break;
			case "portChecking":
				$this->portChecking = (bool) $value;
				break;
			case "packetLimit":
				$this->packetLimit = (int) $value;
				break;
		}
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

	public function shutdown() : void{
		//TODO: this won't flush packets, might result in lost disconnection messages
		foreach($this->sessions as $session){
			$this->removeSession($session);
		}

		$this->socket->close();
		$this->shutdown = true;
	}

	/**
	 * @param InternetAddress $address
	 *
	 * @return Session|null
	 */
	public function getSessionByAddress(InternetAddress $address) : ?Session{
		return $this->sessionsByAddress[$address->toString()] ?? null;
	}

	public function sessionExists(InternetAddress $address) : bool{
		return isset($this->sessionsByAddress[$address->toString()]);
	}

	public function createSession(InternetAddress $address, int $clientId, int $mtuSize) : Session{
		$this->checkSessions();

		while(isset($this->sessions[$this->nextSessionId])){
			$this->nextSessionId++;
			$this->nextSessionId &= 0x7fffffff; //we don't expect more than 2 billion simultaneous connections, and this fits in 4 bytes
		}

		$session = new Session($this, $this->logger, clone $address, $clientId, $mtuSize, $this->nextSessionId);
		$this->sessionsByAddress[$address->toString()] = $session;
		$this->sessions[$this->nextSessionId] = $session;
		$this->logger->debug("Created session for $address with MTU size $mtuSize");

		return $session;
	}

	public function removeSession(Session $session, string $reason = "unknown") : void{
		$id = $session->getInternalId();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			$this->removeSessionInternal($session);
			$this->eventListener->closeSession($id, $reason);
		}
	}

	public function removeSessionInternal(Session $session) : void{
		unset($this->sessionsByAddress[$session->getAddress()->toString()], $this->sessions[$session->getInternalId()]);
	}

	public function openSession(Session $session) : void{
		$address = $session->getAddress();
		$this->eventListener->openSession($session->getInternalId(), $address->ip, $address->port, $session->getID());
	}

	private function checkSessions() : void{
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $sessionId => $session){
				if($session->isTemporal()){
					$this->removeSessionInternal($session);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function notifyACK(Session $session, int $identifierACK) : void{
		$this->eventListener->notifyACK($session->getInternalId(), $identifierACK);
	}

	public function getName() : string{
		return $this->name;
	}

	public function getID() : int{
		return $this->serverId;
	}
}
