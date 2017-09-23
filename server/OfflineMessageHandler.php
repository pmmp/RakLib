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

use raklib\protocol\OfflineMessage;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;

class OfflineMessageHandler{
	/** @var SessionManager */
	private $sessionManager;

	public function __construct(SessionManager $manager){
		$this->sessionManager = $manager;
	}

	public function handle(OfflineMessage $packet, string $source, int $port){
		switch($packet::$ID){
			case UNCONNECTED_PING::$ID:
				/** @var UNCONNECTED_PING $packet */
				$pk = new UNCONNECTED_PONG();
				$pk->serverID = $this->sessionManager->getID();
				$pk->pingID = $packet->pingID;
				$pk->serverName = $this->sessionManager->getName();
				$this->sessionManager->sendPacket($pk, $source, $port);
				return true;
			case OPEN_CONNECTION_REQUEST_1::$ID:
				/** @var OPEN_CONNECTION_REQUEST_1 $packet */
				$packet->protocol; //TODO: check protocol number and refuse connections
				$pk = new OPEN_CONNECTION_REPLY_1();
				$pk->mtuSize = $packet->mtuSize;
				$pk->serverID = $this->sessionManager->getID();
				$this->sessionManager->sendPacket($pk, $source, $port);
				return true;
			case OPEN_CONNECTION_REQUEST_2::$ID:
				/** @var OPEN_CONNECTION_REQUEST_2 $packet */

				if($packet->serverPort === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
					$mtuSize = min(abs($packet->mtuSize), 1464); //Max size, do not allow creating large buffers to fill server memory
					$pk = new OPEN_CONNECTION_REPLY_2();
					$pk->mtuSize = $mtuSize;
					$pk->serverID = $this->sessionManager->getID();
					$pk->clientAddress = $source;
					$pk->clientPort = $port;
					$this->sessionManager->sendPacket($pk, $source, $port);
					$this->sessionManager->createSession($source, $port, $packet->clientID, $mtuSize);
				}else{
					$this->sessionManager->getLogger()->debug("Not creating session for $source $port due to mismatched port, expected " . $this->sessionManager->getPort() . ", got " . $packet->serverPort);
				}

				return true;
		}

		return false;
	}

}