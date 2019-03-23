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

use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use raklib\generic\Socket;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\Packet;
use raklib\RakLib;
use raklib\utils\InternetAddress;
use function asort;
use function bin2hex;
use function chr;
use function count;
use function dechex;
use function get_class;
use function max;
use function microtime;
use function ord;
use function preg_match;
use function serialize;
use function socket_strerror;
use function strlen;
use function substr;
use function time;
use function time_sleep_until;
use function trim;
use const PHP_INT_MAX;
use const SOCKET_ECONNRESET;
use const SOCKET_EWOULDBLOCK;

class SessionManager{

	private const RAKLIB_TPS = 100;
	private const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	/** @var RakLibServer */
	protected $server;
	/** @var Socket */
	protected $socket;

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

	public $portChecking = false;

	/** @var int */
	protected $startTimeMS;

	/** @var int */
	protected $maxMtuSize;

	protected $reusableAddress;

	/** @var int */
	protected $nextSessionId = 0;

	public function __construct(RakLibServer $server, Socket $socket, int $maxMtuSize){
		$this->server = $server;
		$this->socket = $socket;

		$this->startTimeMS = (int) (microtime(true) * 1000);
		$this->maxMtuSize = $maxMtuSize;

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
		return $this->server->getProtocolVersion();
	}

	public function getLogger() : \ThreadedLogger{
		return $this->server->getLogger();
	}

	public function run() : void{
		$this->tickProcessor();
	}

	private function tickProcessor() : void{
		$this->lastMeasure = microtime(true);

		while(!$this->shutdown){
			$start = microtime(true);
			while($this->receivePacket()){}
			while($this->receiveStream()){}
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
				$this->streamOption("bandwidth", serialize([
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

		$len = $this->socket->readPacket($buffer, $address->ip, $address->port);
		if($len === false){
			$error = $this->socket->getLastError();
			if($error === SOCKET_EWOULDBLOCK){ //no data
				return false;
			}elseif($error === SOCKET_ECONNRESET){ //client disconnected improperly, maybe crash or lost connection
				return true;
			}

			$this->getLogger()->debug("Socket error occurred while trying to recv ($error): " . trim(socket_strerror($error)));
			return false;
		}

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
					if($header & Datagram::BITFLAG_ACK){
						$session->handlePacket(new ACK($buffer));
					}elseif($header & Datagram::BITFLAG_NAK){
						$session->handlePacket(new NACK($buffer));
					}else{
						$session->handlePacket(new Datagram($buffer));
					}
				}else{
					$this->server->getLogger()->debug("Ignored unconnected packet from $address due to session already opened (0x" . bin2hex($buffer[0]) . ")");
				}
			}elseif(!$this->offlineMessageHandler->handleRaw($buffer, $address)){
				foreach($this->rawPacketFilters as $pattern){
					if(preg_match($pattern, $buffer) > 0){
						$this->streamRaw($address, $buffer);
						break;
					}
				}

				$this->server->getLogger()->debug("Ignored packet from $address due to no session opened (0x" . bin2hex($buffer[0]) . ")");
			}
		}catch(BinaryDataException $e){
			$logger = $this->getLogger();
			$logger->synchronized(function() use($logger, $address, $e, $buffer): void{
				$logger->debug("Packet from $address (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer));
				$logger->debug(get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
				foreach($this->server->getTrace(0, $e->getTrace()) as $line){
					$logger->debug($line);
				}
				$logger->error("Bad packet from $address: " . $e->getMessage());
			});
			$this->blockAddress($address->ip, 5);
		}

		return true;
	}

	public function sendPacket(Packet $packet, InternetAddress $address) : void{
		$packet->encode();
		$this->sendBytes += $this->socket->writePacket($packet->getBuffer(), $address->ip, $address->port);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$buffer = chr(ITCProtocol::PACKET_ENCAPSULATED) . Binary::writeInt($session->getInternalId()) . chr($flags) . $packet->toInternalBinary();
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamRaw(InternetAddress $source, string $payload) : void{
		$buffer = chr(ITCProtocol::PACKET_RAW) . chr(strlen($source->ip)) . $source->ip . Binary::writeShort($source->port) . $payload;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamClose(int $identifier, string $reason) : void{
		$buffer = chr(ITCProtocol::PACKET_CLOSE_SESSION) . Binary::writeInt($identifier) . chr(strlen($reason)) . $reason;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamInvalid(int $identifier) : void{
		$buffer = chr(ITCProtocol::PACKET_INVALID_SESSION) . Binary::writeInt($identifier);
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOpen(Session $session) : void{
		$address = $session->getAddress();
		$buffer = chr(ITCProtocol::PACKET_OPEN_SESSION) . Binary::writeInt($session->getInternalId()) . chr(strlen($address->ip)) . $address->ip . Binary::writeShort($address->port) . Binary::writeLong($session->getID());
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamACK(int $identifier, int $identifierACK) : void{
		$buffer = chr(ITCProtocol::PACKET_ACK_NOTIFICATION) . Binary::writeInt($identifier) . Binary::writeInt($identifierACK);
		$this->server->pushThreadToMainPacket($buffer);
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 */
	protected function streamOption(string $name, $value) : void{
		$buffer = chr(ITCProtocol::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamPingMeasure(Session $session, int $pingMS) : void{
		$buffer = chr(ITCProtocol::PACKET_REPORT_PING) . Binary::writeInt($session->getInternalId()) . Binary::writeInt($pingMS);
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function receiveStream() : bool{
		if(($packet = $this->server->readMainToThreadPacket()) !== null){
			$id = ord($packet{0});
			$offset = 1;
			if($id === ITCProtocol::PACKET_ENCAPSULATED){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				$offset += 4;
				$session = $this->sessions[$identifier] ?? null;
				if($session !== null and $session->isConnected()){
					$flags = ord($packet{$offset++});
					$buffer = substr($packet, $offset);
					$session->addEncapsulatedToQueue(EncapsulatedPacket::fromInternalBinary($buffer), $flags);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === ITCProtocol::PACKET_RAW){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$this->socket->writePacket($payload, $address, $port);
			}elseif($id === ITCProtocol::PACKET_CLOSE_SESSION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				if(isset($this->sessions[$identifier])){
					$this->sessions[$identifier]->flagForDisconnection();
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === ITCProtocol::PACKET_INVALID_SESSION){
				$identifier = Binary::readInt(substr($packet, $offset, 4));
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === ITCProtocol::PACKET_SET_OPTION){
				$len = ord($packet{$offset++});
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
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
			}elseif($id === ITCProtocol::PACKET_BLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$timeout = Binary::readInt(substr($packet, $offset, 4));
				$this->blockAddress($address, $timeout);
			}elseif($id === ITCProtocol::PACKET_UNBLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$this->unblockAddress($address);
			}elseif($id === ITCProtocol::PACKET_RAW_FILTER){
				$pattern = substr($packet, $offset);
				$this->rawPacketFilters[] = $pattern;
			}elseif($id === ITCProtocol::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}

				$this->socket->close();
				$this->shutdown = true;
			}elseif($id === ITCProtocol::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}else{
				$this->getLogger()->debug("Unknown RakLib internal packet (ID 0x" . dechex($id) . ") received from main thread");
			}

			return true;
		}

		return false;
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$final = time() + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				$this->getLogger()->notice("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->block[$address]);
		$this->getLogger()->debug("Unblocked $address");
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

		$session = new Session($this, $this->server->getLogger(), clone $address, $clientId, $mtuSize, $this->nextSessionId);
		$this->sessionsByAddress[$address->toString()] = $session;
		$this->sessions[$this->nextSessionId] = $session;
		$this->getLogger()->debug("Created session for $address with MTU size $mtuSize");

		return $session;
	}

	public function removeSession(Session $session, string $reason = "unknown") : void{
		$id = $session->getInternalId();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			$this->removeSessionInternal($session);
			$this->streamClose($id, $reason);
		}
	}

	public function removeSessionInternal(Session $session) : void{
		unset($this->sessionsByAddress[$session->getAddress()->toString()], $this->sessions[$session->getInternalId()]);
	}

	public function openSession(Session $session) : void{
		$this->streamOpen($session);
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
		$this->streamACK($session->getInternalId(), $identifierACK);
	}

	public function getName() : string{
		return $this->name;
	}

	public function getID() : int{
		return $this->server->getServerId();
	}
}
