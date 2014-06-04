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

namespace raklib;


//Dependencies check
$errors = 0;
if(version_compare("5.4.0", PHP_VERSION) > 0){
	echo "[CRITICAL] Use PHP >= 5.4.0" . PHP_EOL;
	++$errors;
}

if(!extension_loaded("sockets")){
	echo "[CRITICAL] Unable to find the Socket extension." . PHP_EOL;
	++$errors;
}

if(!extension_loaded("pthreads")){
	echo "[CRITICAL] Unable to find the pthreads extension." . PHP_EOL;
	++$errors;
}else{
	$pthreads_version = phpversion("pthreads");
	if(substr_count($pthreads_version, ".") < 2){
		$pthreads_version = "0.$pthreads_version";
	}
	if(version_compare($pthreads_version, "2.0.4") < 0){
		echo "[CRITICAL] pthreads >= 2.0.4 is required, while you have $pthreads_version.";
		++$errors;
	}
}

if($errors > 0){
	exit(1); //Exit with error
}
unset($errors);

abstract class RakLib{
	const VERSION = "0.0.1";
	const PROTOCOL = 5;
	const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

	const PACKET_ENCAPSULATED = 0x01;

	public static function bootstrap(\SplAutoloader $loader){
		$loader->add("raklib", array(
			dirname(__FILE__) . DIRECTORY_SEPARATOR . ".."
		));
	}
}