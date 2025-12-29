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

use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use raklib\generic\Session;
use raklib\protocol\IncompatibleProtocolVersion;
use raklib\protocol\MessageIdentifiers;
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
use function crc32;
use function get_class;
use function min;
use function ord;
use function random_int;
use function strlen;
use function substr;

class UnconnectedMessageHandler{
	/**
	 * @var OfflineMessage[]|\SplFixedArray
	 * @phpstan-var \SplFixedArray<OfflineMessage>
	 */
	private \SplFixedArray $packetPool;

	private string $currentCookieSalt;
	private string $previousCookieSalt;
	private int $cookieMismatches = 0;

	public function __construct(
		private Server $server,
		private ProtocolAcceptor $protocolAcceptor,
		private bool $antiIpSpoofCookies = true
	){
		$this->registerPackets();

		$this->currentCookieSalt = $this->previousCookieSalt = self::newCookieSalt();
	}

	private static function newCookieSalt() : string{
		return Binary::writeLong(random_int(PHP_INT_MIN, PHP_INT_MAX));
	}

	public function rotateCookieSalts() : void{
		$this->previousCookieSalt = $this->currentCookieSalt;
		$this->currentCookieSalt = self::newCookieSalt();
		$this->cookieMismatches = 0;
	}

	private static function calculateCookieWithSalt(InternetAddress $address, string $salt) : int{
		$preimage = strlen($address->getIp()) . $address->getIp() . Binary::writeShort($address->getPort()) . $salt;
		return crc32($preimage);
	}

	/**
	 * Calculates a cookie using the current cookie salt and the provided IP address.
	 * Cookie salt is a server-side secret, so the client cannot guess it.
	 */
	private function calculateCookie(InternetAddress $address) : int{
		return self::calculateCookieWithSalt($address, $this->currentCookieSalt);
	}

	/**
	 * Returns the number of times we detected a cookie mismatch since the salt was last rotated.
	 * May be useful for reporting spoofed IP attacks.
	 */
	public function getCookieMismatchSinceLastRotation() : int{
		return $this->cookieMismatches;
	}

	/**
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
				$this->server->sendPacket(OpenConnectionReply1::create(
					$this->server->getID(),
					$this->antiIpSpoofCookies ? $this->calculateCookie($address) : null,
					$packet->mtuSize + 28),
					$address
				);
			}
		}elseif($packet instanceof OpenConnectionRequest2){
			if($this->antiIpSpoofCookies){
				$cookie1 = $this->calculateCookie($address);
				$cookie2 = self::calculateCookieWithSalt($address, $this->previousCookieSalt);
				if($packet->cookie !== $cookie1 && $packet->cookie !== $cookie2){
					$this->cookieMismatches++;
					//don't log this by default, we don't want to let an attacker LogDoS us
					//we also don't block the IP since this is probably coming from a spoofed IP
					//$this->server->getLogger()->debug("Not creating session for $address due to cookie mismatch (expected $cookie1 or $cookie2, but got $packet->cookie)");
					return true;
				}else{
					$this->server->getLogger()->debug("Cookie check succeeded for $address with cookie $packet->cookie (cookie1: $cookie1, cookie2: $cookie2)");
				}
			}else{
				$this->server->getLogger()->debug("No cookie check performed for $address");
			}

			if($packet->serverAddress->getPort() === $this->server->getPort() or !$this->server->portChecking){
				if($packet->mtuSize < Session::MIN_MTU_SIZE){
					$this->server->getLogger()->debug("Not creating session for $address due to bad MTU size $packet->mtuSize");
					return true;
				}
				$existingSession = $this->server->getSessionByAddress($address);
				if($existingSession !== null && $existingSession->isConnected()){
					//for redundancy, in case someone rips up Server - we really don't want connected sessions getting
					//overwritten
					$this->server->getLogger()->debug("Not creating session for $address due to session already opened");
					return true;
				}
				$mtuSize = min($packet->mtuSize, $this->server->getMaxMtuSize()); //Max size, do not allow creating large buffers to fill server memory
				$this->server->sendPacket(OpenConnectionReply2::create($this->server->getID(), $address, $mtuSize, false), $address);
				$this->server->createSession($address, $packet->clientID, $mtuSize);
			}else{
				$this->server->getLogger()->debug("Not creating session for $address due to mismatched port, expected " . $this->server->getPort() . ", got " . $packet->serverAddress->getPort());
			}
		}else{
			return false;
		}

		return true;
	}

	/**
	 * @phpstan-param class-string<OfflineMessage> $class
	 */
	private function registerPacket(int $id, string $class) : void{
		$this->packetPool[$id] = new $class;
	}

	public function getPacketFromPool(string $buffer) : ?OfflineMessage{
		$pk = $this->packetPool[ord($buffer[0])];
		if($pk !== null){
			return clone $pk;
		}

		return null;
	}

	private function registerPackets() : void{
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(MessageIdentifiers::ID_UNCONNECTED_PING, UnconnectedPing::class);
		$this->registerPacket(MessageIdentifiers::ID_UNCONNECTED_PING_OPEN_CONNECTIONS, UnconnectedPingOpenConnections::class);
		$this->registerPacket(MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1, OpenConnectionRequest1::class);
		$this->registerPacket(MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2, OpenConnectionRequest2::class);
	}

}
