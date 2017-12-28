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

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\AdvertiseSystem;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\Packet;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;
use raklib\RakLib;

class SessionManager{

	const RAKLIB_TPS = 100;
	const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	/** @var \SplFixedArray<Packet|null> */
	protected $packetPool;

	/** @var RakLibServer */
	protected $server;
	/** @var UDPServerSocket */
	protected $socket;

	/** @var int */
	protected $receiveBytes = 0;
	/** @var int */
	protected $sendBytes = 0;

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

	/** @var float[] string (address) => float (unblock time) */
	protected $block = [];
	/** @var int[] string (address) => int (number of packets) */
	protected $ipSec = [];

	public $portChecking = false;

	/** @var int */
	protected $startTimeMS;

	public function __construct(RakLibServer $server, UDPServerSocket $socket){
		$this->server = $server;
		$this->socket = $socket;

		$this->startTimeMS = (int) (microtime(true) * 1000);

		$this->offlineMessageHandler = new OfflineMessageHandler($this);

		$this->registerPackets();

		$this->run();
	}

	/**
	 * Returns the time in milliseconds since server start.
	 * @return int
	 */
	public function getRakNetTimeMS() : int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	public function getPort(){
		return $this->server->getPort();
	}

	public function getLogger(){
		return $this->server->getLogger();
	}

	public function run(){
		$this->tickProcessor();
	}

	private function tickProcessor(){
		$this->lastMeasure = microtime(true);

		while(!$this->shutdown){
			$start = microtime(true);
			while($this->receivePacket()){}
			while($this->receiveStream()){}
			$time = microtime(true) - $start;
			if($time < self::RAKLIB_TIME_PER_TICK){
				@time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
			}
			$this->tick();
		}
	}

	private function tick(){
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		$this->ipSec = [];

		if(($this->ticks % self::RAKLIB_TPS) === 0){
			$diff = max(0.005, $time - $this->lastMeasure);
			$this->streamOption("bandwidth", serialize([
				"up" => $this->sendBytes / $diff,
				"down" => $this->receiveBytes / $diff
			]));
			$this->lastMeasure = $time;
			$this->sendBytes = 0;
			$this->receiveBytes = 0;

			if(count($this->block) > 0){
				asort($this->block);
				$now = microtime(true);
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


	private function receivePacket(){
		$len = $this->socket->readPacket($buffer, $source, $port);
		if($buffer !== null){
			$this->receiveBytes += $len;
			if(isset($this->block[$source])){
				return true;
			}

			if(isset($this->ipSec[$source])){
				if(++$this->ipSec[$source] >= $this->packetLimit){
					$this->blockAddress($source);
					return true;
				}
			}else{
				$this->ipSec[$source] = 1;
			}

			if($len > 0){
				try{
					$pid = ord($buffer{0});

					$session = $this->getSession($source, $port);
					if($session === null){
						$pk = $this->getPacketFromPool($pid, $buffer);
						if($pk instanceof OfflineMessage){
							$pk->decode();
							if($pk->isValid()){
								if(!$this->offlineMessageHandler->handle($pk, $source, $port)){
									$this->server->getLogger()->debug("Unhandled offline message " . get_class($pk) . " received from $source $port");
								}
							}else{
								$this->server->getLogger()->debug("Received garbage message from $source $port: " . bin2hex($pk->buffer));
							}
						}else{
							$this->streamRaw($source, $port, $buffer);
						}
					}else{
						if(($pid & Datagram::BITFLAG_VALID) === 0){
							$this->server->getLogger()->debug("Ignored non-connected message 0x" . bin2hex($buffer{0}) . " from $source $port due to session already opened");
						}else{
							if($pid & Datagram::BITFLAG_ACK){
								$session->handlePacket(new ACK($buffer));
							}elseif($pid & Datagram::BITFLAG_NAK){
								$session->handlePacket(new NACK($buffer));
							}else{
								$session->handlePacket(new Datagram($buffer));
							}
						}
					}
				}catch(\Throwable $e){
					$logger = $this->getLogger();
					$logger->debug("Packet from $source $port (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer));
					$logger->logException($e);
					$this->blockAddress($source, 5);
				}
			}

			return true;
		}

		return false;
	}

	public function sendPacket(Packet $packet, $dest, $port){
		$packet->encode();
		$this->sendBytes += $this->socket->writePacket($packet->buffer, $dest, $port);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){
		$id = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toBinary(true);
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamRaw($address, $port, $payload){
		$buffer = chr(RakLib::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamClose($identifier, $reason){
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamInvalid($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOpen(Session $session){
		$identifier = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamACK($identifier, $identifierACK){
		$buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOption($name, $value){
		$buffer = chr(RakLib::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamPingMeasure(Session $session, int $pingMS){
		$identifier = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_REPORT_PING) . chr(strlen($identifier)) . $identifier . Binary::writeInt($pingMS);
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function receiveStream(){
		if(($packet = $this->server->readMainToThreadPacket()) !== null){
			$id = ord($packet{0});
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$session = $this->sessions[$identifier] ?? null;
				if($session !== null and $session->isConnected()){
					$flags = ord($packet{$offset++});
					$buffer = substr($packet, $offset);
					$session->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer, true), $flags);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_RAW){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$this->socket->writePacket($payload, $address, $port);
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->sessions[$identifier]->flagForDisconnection();
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === RakLib::PACKET_SET_OPTION){
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
			}elseif($id === RakLib::PACKET_BLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$timeout = Binary::readInt(substr($packet, $offset, 4));
				$this->blockAddress($address, $timeout);
			}elseif($id === RakLib::PACKET_UNBLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$this->unblockAddress($address);
			}elseif($id === RakLib::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}

				$this->socket->close();
				$this->shutdown = true;
			}elseif($id === RakLib::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}else{
				return false;
			}

			return true;
		}

		return false;
	}

	public function blockAddress($address, $timeout = 300){
		$final = microtime(true) + $timeout;
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

	public function unblockAddress(string $address){
		unset($this->block[$address]);
		$this->getLogger()->debug("Unblocked $address");
	}

	/**
	 * @param string $ip
	 * @param int    $port
	 *
	 * @return Session|null
	 */
	public function getSession($ip, $port){
		$id = $ip . ":" . $port;
		return $this->sessions[$id] ?? null;
	}

	public function createSession(string $ip, int $port, $clientId, int $mtuSize){
		$this->checkSessions();

		$this->sessions[$ip . ":" . $port] = $session = new Session($this, $ip, $port, $clientId, $mtuSize);
		$this->getLogger()->debug("Created session for $ip $port with MTU size $mtuSize");

		return $session;
	}

	public function removeSession(Session $session, $reason = "unknown"){
		$id = $session->getAddress() . ":" . $session->getPort();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			$this->removeSessionInternal($session);
			$this->streamClose($id, $reason);
		}
	}

	public function removeSessionInternal(Session $session){
		unset($this->sessions[$session->getAddress() . ":" . $session->getPort()]);
	}

	public function openSession(Session $session){
		$this->streamOpen($session);
	}

	private function checkSessions(){
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $i => $s){
				if($s->isTemporal()){
					unset($this->sessions[$i]);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function notifyACK(Session $session, $identifierACK){
		$this->streamACK($session->getAddress() . ":" . $session->getPort(), $identifierACK);
	}

	public function getName(){
		return $this->name;
	}

	public function getID(){
		return $this->server->getServerId();
	}

	/**
	 * @param int    $id
	 * @param string $class
	 */
	private function registerPacket($id, $class){
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param int    $id
	 * @param string $buffer
	 *
	 * @return Packet|null
	 */
	public function getPacketFromPool(int $id, string $buffer = ""){
		$pk = $this->packetPool[$id];
		if($pk !== null){
			$pk = clone $pk;
			$pk->buffer = $buffer;
			return $pk;
		}

		return null;
	}

	private function registerPackets(){
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionRequest1::$ID, OpenConnectionRequest1::class);
		$this->registerPacket(OpenConnectionReply1::$ID, OpenConnectionReply1::class);
		$this->registerPacket(OpenConnectionRequest2::$ID, OpenConnectionRequest2::class);
		$this->registerPacket(OpenConnectionReply2::$ID, OpenConnectionReply2::class);
		$this->registerPacket(UnconnectedPong::$ID, UnconnectedPong::class);
		$this->registerPacket(AdvertiseSystem::$ID, AdvertiseSystem::class);
	}
}
