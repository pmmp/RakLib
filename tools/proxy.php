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

declare(strict_types=1);

use pocketmine\utils\Limits;
use raklib\client\ClientSocket;
use raklib\generic\SocketException;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\UnconnectedPong;
use raklib\server\ProtocolAcceptor;
use raklib\server\Server;
use raklib\server\ServerSocket;
use raklib\server\SimpleProtocolAcceptor;
use raklib\utils\InternetAddress;
use raklib\generic\Socket;

require dirname(__DIR__) . '/vendor/autoload.php';

$bindAddr = "0.0.0.0";
$bindPort = 19132;

if(count($argv) === 3){
	$serverAddress = $argv[1];
	$serverPort = (int) $argv[2];
}elseif(count($argv) === 1){
	echo "Enter server address: ";
	$serverAddress = fgets(STDIN);
	if($serverAddress === false){ //ctrl+c or ctrl+d
		exit(1);
	}
	$serverAddress = trim($serverAddress);
	echo "Enter server port: ";
	$input = fgets(STDIN);
	if($input === false){ //ctrl+c or ctrl+d
		exit(1);
	}
	$serverPort = (int) trim($input);
}else{
	echo "Usage: php proxy.php [bind address] [bind port]\n";
	exit(1);
}

$serverAddress = gethostbyname($serverAddress);

if($serverPort !== 19132){
	\GlobalLogger::get()->warning("You may experience problems connecting to PocketMine-MP servers on ports other than 19132 if the server has port checking enabled");
}

\GlobalLogger::get()->info("Opening listen socket");
try{
	$proxyToServerUnconnectedSocket = new ClientSocket(new InternetAddress($serverAddress, $serverPort, 4));
	$proxyToServerUnconnectedSocket->setBlocking(false);
}catch(SocketException $e){
	\GlobalLogger::get()->emergency("Can't connect to $serverAddress on port $serverPort, is the server online?");
	\GlobalLogger::get()->emergency($e->getMessage());
	exit(1);
}
socket_getsockname($proxyToServerUnconnectedSocket->getSocket(), $proxyAddr, $proxyPort);
\GlobalLogger::get()->info("Listening on $bindAddr:$bindPort, sending from $proxyAddr:$proxyPort, sending to $serverAddress:$serverPort");

try{
	$clientProxySocket = new ServerSocket(new InternetAddress($bindAddr, $bindPort, 4));
	$clientProxySocket->setBlocking(false);
}catch(SocketException $e){
	\GlobalLogger::get()->emergency("Can't bind to $bindAddr on port $bindPort, is something already using that port?");
	\GlobalLogger::get()->emergency($e->getMessage());
	exit(1);
}

\GlobalLogger::get()->info("Press CTRL+C to stop the proxy");

$clientAddr = $clientPort = null;

class ClientSession{
	private int $lastUsedTime;

	public function __construct(
		private InternetAddress $address,
		private ClientSocket $proxyToServerSocket
	){
		$this->lastUsedTime = time();
	}

	public function getAddress() : InternetAddress{
		return $this->address;
	}

	public function getSocket() : ClientSocket{
		return $this->proxyToServerSocket;
	}

	public function setActive() : void{
		$this->lastUsedTime = time();
	}

	public function isActive() : bool{
		return time() - $this->lastUsedTime < 10;
	}
}

function serverToClientRelay(ClientSession $client, ServerSocket $clientProxySocket) : void{
	$buffer = $client->getSocket()->readPacket();
	if($buffer !== null){
		$clientProxySocket->writePacket($buffer, $client->getAddress()->getIp(), $client->getAddress()->getPort());
	}
}

/** @var ClientSession[][] $clients */
$clients = [];

$serverId = mt_rand(0, Limits::INT32_MAX);
$mostRecentPong = null;

while(true){
	$k = 0;
	$r = [];
	$r[++$k] = $clientProxySocket->getSocket();
	$r[++$k] = $proxyToServerUnconnectedSocket->getSocket();
	$clientIndex = [];
	foreach($clients as $ipClients){
		foreach($ipClients as $client){
			$key = ++$k;
			$r[$key] = $client->getSocket()->getSocket();
			$clientIndex[$key] = $client;
		}
	}
	$w = $e = null;
	if(socket_select($r, $w, $e, 10) > 0){
		foreach($r as $key => $socket){
			if(isset($clientIndex[$key])){
				serverToClientRelay($clientIndex[$key], $clientProxySocket);
			}elseif($socket === $proxyToServerUnconnectedSocket->getSocket()){
				$buffer = $proxyToServerUnconnectedSocket->readPacket();
				if($buffer !== null && $buffer !== "" && ord($buffer[0]) === MessageIdentifiers::ID_UNCONNECTED_PONG){
					$mostRecentPong = $buffer;
					\GlobalLogger::get()->info("Caching ping response from server: " . $buffer);
				}
			}elseif($socket === $clientProxySocket->getSocket()){
				try{
					$buffer = $clientProxySocket->readPacket($recvAddr, $recvPort);
				}catch(SocketException $e){
					$error = $e->getCode();
					if($error === SOCKET_ECONNRESET){ //client disconnected improperly, maybe crash or lost connection
						continue;
					}

					\GlobalLogger::get()->error("Socket error: " . $e->getMessage());
					continue;
				}

				if($buffer === null || $buffer === ""){
					continue;
				}
				if(isset($clients[$recvAddr][$recvPort])){
					$client = $clients[$recvAddr][$recvPort];
					$client->setActive();
					$client->getSocket()->writePacket($buffer);
				}elseif(ord($buffer[0]) === MessageIdentifiers::ID_UNCONNECTED_PING){
					\GlobalLogger::get()->info("Got ping from $recvAddr on port $recvPort, pinging server");
					$proxyToServerUnconnectedSocket->writePacket($buffer);

					if($mostRecentPong !== null){
						$clientProxySocket->writePacket($mostRecentPong, $recvAddr, $recvPort);
					}else{
						\GlobalLogger::get()->info("No cached ping response, waiting for server to respond");
					}
				}elseif(ord($buffer[0]) === MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1){
					\GlobalLogger::get()->info("Got connection from $recvAddr on port $recvPort");
					$proxyToServerUnconnectedSocket->writePacket($buffer);
					$client = new ClientSession(new InternetAddress($recvAddr, $recvPort, 4), new ClientSocket(new InternetAddress($serverAddress, $serverPort, 4)));
					$client->getSocket()->setBlocking(false);
					$clients[$recvAddr][$recvPort] = $client;
					socket_getsockname($client->getSocket()->getSocket(), $proxyAddr, $proxyPort);
					\GlobalLogger::get()->info("Established connection: $recvAddr:$recvPort <-> $proxyAddr:$proxyPort <-> $serverAddress:$serverPort");
				}else{
					\GlobalLogger::get()->warning("Unexpected packet from unconnected client $recvAddr on port $recvPort: " . bin2hex($buffer));
				}
			}else{
				throw new \LogicException("Unexpected socket in select result");
			}
		}
	}
	foreach($clients as $ip => $ipClients){
		foreach($ipClients as $port => $client){
			if(!$client->isActive()){
				\GlobalLogger::get()->info("Closing session for client $ip:$port");
				unset($clients[$ip][$port]);
			}
		}
	}
}