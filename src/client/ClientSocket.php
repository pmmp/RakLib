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

namespace raklib\client;

use raklib\generic\Socket;
use raklib\generic\SocketException;
use raklib\utils\InternetAddress;
use function socket_connect;
use function socket_last_error;
use function socket_recv;
use function socket_send;
use function socket_strerror;
use function strlen;
use function trim;

class ClientSocket extends Socket{

	public function __construct(
		private InternetAddress $connectAddress
	){
		parent::__construct($this->connectAddress->getVersion() === 6);

		if(!@socket_connect($this->socket, $this->connectAddress->getIp(), $this->connectAddress->getPort())){
			$error = socket_last_error($this->socket);
			throw new SocketException("Failed to connect to " . $this->connectAddress . ": " . trim(socket_strerror($error)), $error);
		}
		//TODO: is an 8 MB buffer really appropriate for a client??
		$this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
	}

	public function getConnectAddress() : InternetAddress{
		return $this->connectAddress;
	}

	/**
	 * @throws SocketException
	 */
	public function readPacket() : ?string{
		$buffer = "";
		if(@socket_recv($this->socket, $buffer, 65535, 0) === false){
			$errno = socket_last_error($this->socket);
			if($errno === SOCKET_EWOULDBLOCK){
				return null;
			}
			throw new SocketException("Failed to recv (errno $errno): " . trim(socket_strerror($errno)), $errno);
		}
		return $buffer;
	}

	/**
	 * @throws SocketException
	 */
	public function writePacket(string $buffer) : int{
		$result = @socket_send($this->socket, $buffer, strlen($buffer), 0);
		if($result === false){
			$errno = socket_last_error($this->socket);
			throw new SocketException("Failed to send packet (errno $errno): " . trim(socket_strerror($errno)), $errno);
		}
		return $result;
	}
}
