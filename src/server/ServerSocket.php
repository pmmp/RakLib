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

use raklib\generic\Socket;
use raklib\generic\SocketException;
use raklib\utils\InternetAddress;
use function socket_bind;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_option;
use function socket_strerror;
use function strlen;
use function trim;

class ServerSocket extends Socket{

	public function __construct(
		private InternetAddress $bindAddress
	){
		parent::__construct($this->bindAddress->getVersion() === 6);

		if(@socket_bind($this->socket, $this->bindAddress->getIp(), $this->bindAddress->getPort()) === true){
			$this->setSendBuffer(1024 * 1024 * 8)->setRecvBuffer(1024 * 1024 * 8);
		}else{
			$error = socket_last_error($this->socket);
			if($error === SOCKET_EADDRINUSE){ //platform error messages aren't consistent
				throw new SocketException("Failed to bind socket: Something else is already running on $this->bindAddress", $error);
			}
			throw new SocketException("Failed to bind to " . $this->bindAddress . ": " . trim(socket_strerror($error)), $error);
		}
	}

	public function getBindAddress() : InternetAddress{
		return $this->bindAddress;
	}

	public function enableBroadcast() : bool{
		return socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1);
	}

	public function disableBroadcast() : bool{
		return socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 0);
	}

	/**
	 * @param string $source reference parameter
	 * @param int    $port reference parameter
	 *
	 * @throws SocketException
	 */
	public function readPacket(?string &$source, ?int &$port) : ?string{
		$buffer = "";
		if(@socket_recvfrom($this->socket, $buffer, 65535, 0, $source, $port) === false){
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
	public function writePacket(string $buffer, string $dest, int $port) : int{
		$result = @socket_sendto($this->socket, $buffer, strlen($buffer), 0, $dest, $port);
		if($result === false){
			$errno = socket_last_error($this->socket);
			throw new SocketException("Failed to send to $dest $port (errno $errno): " . trim(socket_strerror($errno)), $errno);
		}
		return $result;
	}
}
