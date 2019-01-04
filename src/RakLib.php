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

use function defined;
use function extension_loaded;
use function phpversion;
use function substr_count;
use function version_compare;
use const PHP_EOL;
use const PHP_VERSION;

//Dependencies check
$errors = 0;
if(version_compare(RakLib::MIN_PHP_VERSION, PHP_VERSION) > 0){
	echo "[CRITICAL] Use PHP >= " . RakLib::MIN_PHP_VERSION . PHP_EOL;
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

if(!defined('AF_INET6')){
	echo "[CRITICAL] This build of PHP does not support IPv6. IPv6 support is required.";
	++$errors;
}

if($errors > 0){
	exit(1); //Exit with error
}
unset($errors, $exts);

abstract class RakLib{
	public const VERSION = "0.12.0";

	public const MIN_PHP_VERSION = "7.2.0";

	/**
	 * Default vanilla Raknet protocol version that this library implements. Things using RakNet can override this
	 * protocol version with something different.
	 */
	public const DEFAULT_PROTOCOL_VERSION = 6;

	public const PRIORITY_NORMAL = 0;
	public const PRIORITY_IMMEDIATE = 1;

	public const FLAG_NEED_ACK = 0b00001000;

	/**
	 * Regular RakNet uses 10 by default. MCPE uses 20. Configure this value as appropriate.
	 * @var int
	 */
	public static $SYSTEM_ADDRESS_COUNT = 20;
}
