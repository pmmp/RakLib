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

namespace raklib;

const MIN_PHP_VERSION = "7.2.0RC3";

//Dependencies check
$errors = 0;
if(version_compare(MIN_PHP_VERSION, PHP_VERSION) > 0){
	echo "[CRITICAL] Use PHP >= " . MIN_PHP_VERSION . PHP_EOL;
	++$errors;
}

$exts = [
	"bcmath" => "BC Math",
	"pthreads" => "pthreads",
	"sockets" => "Sockets"
];

foreach($exts as $ext => $name){
	if(!extension_loaded($ext)){
		echo "[CRITICAL] Unable to find the $name ($ext) extension." . PHP_EOL;
		++$errors;
	}
}

if(extension_loaded("pthreads")){
	$pthreads_version = phpversion("pthreads");
	if(substr_count($pthreads_version, ".") < 2){
		$pthreads_version = "0.$pthreads_version";
	}

	if(version_compare($pthreads_version, "3.1.7dev") < 0){
		echo "[CRITICAL] pthreads >= 3.1.7dev is required, while you have $pthreads_version.";
		++$errors;
	}
}

if($errors > 0){
	exit(1); //Exit with error
}
unset($errors, $exts);

abstract class RakLib{
	const VERSION = "0.9.0";
	const PROTOCOL = 6;
	const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

	const PRIORITY_NORMAL = 0;
	const PRIORITY_IMMEDIATE = 1;

	const FLAG_NEED_ACK = 0b00001000;

	/*
	 * These internal "packets" DO NOT exist in the RakNet protocol. They are used by the RakLib API to communicate
	 * messages between the RakLib thread and the implementation's thread.
	 *
	 * Internal Packet:
	 * int32 (length without this field)
	 * byte (packet ID)
	 * payload
	 */

	/*
	 * ENCAPSULATED payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 * byte (flags, last 3 bits, priority)
	 * payload (binary internal EncapsulatedPacket)
	 */
	const PACKET_ENCAPSULATED = 0x01;

	/*
	 * OPEN_SESSION payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 * byte (address length)
	 * byte[] (address)
	 * short (port)
	 * long (clientID)
	 */
	const PACKET_OPEN_SESSION = 0x02;

	/*
	 * CLOSE_SESSION payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 * string (reason)
	 */
	const PACKET_CLOSE_SESSION = 0x03;

	/*
	 * INVALID_SESSION payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 */
	const PACKET_INVALID_SESSION = 0x04;

	/* TODO: implement this
	 * SEND_QUEUE payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 */
	const PACKET_SEND_QUEUE = 0x05;

	/*
	 * ACK_NOTIFICATION payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 * int (identifierACK)
	 */
	const PACKET_ACK_NOTIFICATION = 0x06;

	/*
	 * SET_OPTION payload:
	 * byte (option name length)
	 * byte[] (option name)
	 * byte[] (option value)
	 */
	const PACKET_SET_OPTION = 0x07;

	/*
	 * RAW payload:
	 * byte (address length)
	 * byte[] (address from/to)
	 * short (port)
	 * byte[] (payload)
	 */
	const PACKET_RAW = 0x08;

	/*
	 * BLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 * int (timeout)
	 */
	const PACKET_BLOCK_ADDRESS = 0x09;

	/*
	 * UNBLOCK_ADDRESS payload:
	 * byte (address length)
	 * byte[] (address)
	 */
	const PACKET_UNBLOCK_ADDRESS = 0x10;

	/*
	 * REPORT_PING payload:
	 * byte (identifier length)
	 * byte[] (identifier)
	 * int32 (measured latency in MS)
	 */
	const PACKET_REPORT_PING = 0x11;

	/*
	 * No payload
	 *
	 * Sends the disconnect message, removes sessions correctly, closes sockets.
	 */
	const PACKET_SHUTDOWN = 0x7e;

	/*
	 * No payload
	 *
	 * Leaves everything as-is and halts, other Threads can be in a post-crash condition.
	 */
	const PACKET_EMERGENCY_SHUTDOWN = 0x7f;

	/**
	 * Regular RakNet uses 10 by default. MCPE uses 20. Configure this value as appropriate.
	 * @var int
	 */
	public static $SYSTEM_ADDRESS_COUNT = 20;

	public static function bootstrap(\ClassLoader $loader){
		$loader->addPath(dirname(__FILE__) . DIRECTORY_SEPARATOR . "..");
	}
}
