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

namespace raklib\protocol;

#include <rules/RakLibPacket.h>

use raklib\RakLib;

class ConnectionType{
	
	public const MAX_METADATA_VALUES = 0xff;
	
	public static function createMetaData(string... $metadata) : array{
		if(count($metadata) % 2 != 0){
			throw new \InvalidArgumentException("There must be a value for every key");
		}elseif(count($metadata) / 2 > MAX_METADATA_VALUES){
			throw new \InvalidArgumentException("Too many metadata values");
		}
		
		$metadataArray = array();
		for($i = 0; i < count($metadata); $i += 2){
			$metadataArray[$metadata[$i]] = $metadata[$i + 1];
		}
		return $metadataArray;
	}
	
	/** @var ConnectionType */
	private static $VANILLA = null;
	
	public static function getVanilla() : ConnectionType{
		if($VANILLA === null) {
			$VANILLA = new ConnectionType(null, "Vanilla", null, null, null, true);
		}
		return $VANILLA;
	}
	/** @var ConnectionType */
	private static $RAKLIB = null;
	
	public static function getRakLib() : ConnectionType{
		if($RAKLIB === null) {
			$RAKLIB = new ConnectionType("d22a50b8-d2d7-49eb-ab8e-e5e0e540948e", "RakLib", "PHP", RakLib::VERSION);
		}
		return $RAKLIB;
	}
	
	/** @var byte[] */
	private static $MAGIC = null;
	
	public static function getMagic() : string{
		if($MAGIC === null) {
			$MAGIC = pack("C*", 0x03, 0x08, 0x05, 0x0B, 0x43, 0x54, 0x49);
		}
		return $MAGIC;
	}
	
	/** @var string */
	private $uuid;
	/** @var string */
	private $name;
	/** @var string */
	private $language;
	/** @var string */
	private $version;
	/** @var array */
	private $metadata;
	/** @var bool */
	private $vanilla;
	
	public function __construct(string $uuid, string $name, string $language, string $version, array $metadata = array(), bool $vanilla = false){
		$this->uuid = $uuid;
		$this->name = $name;
		$this->language = $language;
		$this->version = $version;
		$this->metadata = $metadata;
		$this->vanilla = $vanilla;
	}
	
	public function getUUID() : string{
		return $this->uuid;
	}
	
	public function getName() : string{
		return $this->name;
	}
	
	public function getLanguage() : string{
		return $this->language;
	}
	
	public function getVersion() : string{
		return $this->version;
	}
	
	public function getMetaData(string $key) : string{
		return $metadata.get(key);
	}
	
	public function getMetaDataArray() : array{
		return $metadata;
	}
	
	public function isVanilla() : bool{
		return $this->vanilla;
	}
	
}