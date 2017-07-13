<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace raklib\server;

class UDPServerSocket{
	/** @var \Logger */
	protected $logger;
	protected $socket;
	protected $sockets;

	public function __construct(\ThreadedLogger $logger, $port = 19132, $interface = "0.0.0.0"){
		$interfaces = explode(",", $interface);
		foreach($interfaces as $socket_id => $inet_addr){
			$inet_addr = trim($inet_addr);
			if($inet_addr != ""){
				$this->sockets[$socket_id] = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
				//socket_set_option($this->sockets[$socket_id], SOL_SOCKET, SO_BROADCAST, 1); //Allow sending broadcast messages
				if(@socket_bind($this->sockets[$socket_id], $inet_addr, $port) === true){
					socket_set_option($this->sockets[$socket_id], SOL_SOCKET, SO_REUSEADDR, 0);
					$this->setSendBuffer(1024 * 1024 * 8, $socket_id)->setRecvBuffer(1024 * 1024 * 8, $socket_id);
				}else{
					$logger->critical("**** FAILED TO BIND TO " . $inet_addr . ":" . $port . "!");
					$logger->critical("Perhaps a server is already running on that port?");
					exit(1);
				}
				socket_set_nonblock($this->sockets[$socket_id]);
			}
		}
		$this->socket = $this->sockets[0];
	}

	public function getSocket($id = 0){
		return $this->sockets[$id];
	}

	public function close($id = 0){
		if($id = -1){
			foreach($this->sockets as $socket_id => $socket){
				socket_close($socket);
			}
		}else{
			socket_close($this->sockets[$id]);
		}
	}

	/**
	 * @param string &$buffer
	 * @param string &$source
	 * @param int    &$port
	 *
	 * @return int
	 */
	public function readPacket(&$buffer, &$source, &$port, $id = 0){
		return socket_recvfrom($this->sockets[$id], $buffer, 65535, 0, $source, $port);
	}

	public function readPackets(){
		$packets = [];
		foreach($this->sockets as $socket_id => $socket){
			if($len = socket_recvfrom($socket, $buffer, 65535, 0, $source, $port)){
				if($buffer != null){
					$client_id = "{$source}:{$port}";
					$this->interface_map[$client_id] = $socket_id;
					$packets[$client_id] = [$source, $port, $len, $buffer];
				}
			}
		}
		return $packets;
	}

	/**
	 * @param string $buffer
	 * @param string $dest
	 * @param int    $port
	 *
	 * @return int
	 */
	public function writePacket($buffer, $dest, $port){
		$client_id = "{$dest}:{$port}";
		$socket_id = $this->interface_map[$client_id] ?? 0;
		return socket_sendto($this->sockets[$socket_id], $buffer, strlen($buffer), 0, $dest, $port);
	}

	/**
	 * @param int $size
	 *
	 * @return $this
	 */
	public function setSendBuffer($size, $id = 0){
		@socket_set_option($this->sockets[$id], SOL_SOCKET, SO_SNDBUF, $size);

		return $this;
	}

	/**
	 * @param int $size
	 *
	 * @return $this
	 */
	public function setRecvBuffer($size, $id = 0){
		@socket_set_option($this->sockets[$id], SOL_SOCKET, SO_RCVBUF, $size);

		return $this;
	}

}

?>