<?php

declare(strict_types=1);

use pocketmine\utils\Limits;
use raklib\generic\SocketException;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\PacketSerializer;
use raklib\protocol\UnconnectedPing;
use raklib\server\ServerSocket;
use raklib\utils\InternetAddress;

require dirname(__DIR__) . '/vendor/autoload.php';

if(count($argv) === 3){
	$broadcastAddress = $argv[1];
	$port = (int) $argv[2];
}else{
	echo "Usage: php scan.php <broadcast address> <port>" . PHP_EOL;
	exit(1);
}

if(str_contains($broadcastAddress, ".")){
	$bindAddress = new InternetAddress("0.0.0.0", 0, 4);
}else{
	$bindAddress = new InternetAddress("::", 0, 6);
}

$socket = new ServerSocket($bindAddress);
$socket->enableBroadcast();
$clientId = mt_rand(0, Limits::INT32_MAX);
\GlobalLogger::get()->info("Listening on " . $bindAddress);
\GlobalLogger::get()->info("Press CTRL+C to stop");

function sendPing(ServerSocket $socket, string $broadcastAddress, int $port, int $clientId) : void{
	$ping = new UnconnectedPing();
	$ping->clientId = $clientId;
	$ping->sendPingTime = intdiv(hrtime(true), 1_000_000);

	$serializer = new PacketSerializer();
	$ping->encode($serializer);
	$socket->writePacket($serializer->getBuffer(), $broadcastAddress, $port);
}
sendPing($socket, $broadcastAddress, $port, $clientId);

socket_set_option($socket->getSocket(), SOL_SOCKET, SO_RCVTIMEO, ["sec" => 1, "usec" => 0]);
while(true){ //@phpstan-ignore-line
	try{
		$pong = $socket->readPacket($serverIp, $serverPort);
		if($pong !== null && ord($pong[0]) === MessageIdentifiers::ID_UNCONNECTED_PONG){
			\GlobalLogger::get()->info("Pong received from $serverIp:$serverPort: " . $pong);
		}
	}catch(SocketException $e){
		if($e->getCode() === SOCKET_ETIMEDOUT){
			sendPing($socket, $broadcastAddress, $port, $clientId);
		}
	}
}