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

    public const MAGIC = "\x03\x08\x05\x0B\x43\x54\x49";
	
	public static function createMetaData(string ... $metadata) : array{
		if(count($metadata) % 2 != 0){
			throw new InvalidArgumentException("There must be a value for every key");
		}elseif(count($metadata) / 2 > ConnectionType::MAX_METADATA_VALUES){
			throw new InvalidArgumentException("Too many metadata values");
		}
		
		$metadataArray = array();
		for($i = 0; $i < count($metadata); $i += 2){
			$metadataArray[$metadata[$i]] = $metadata[$i + 1];
		}
		return $metadataArray;
	}
	
	/** @var ConnectionType */
	private static $VANILLA = null;
	
	public static function getVanilla() : ConnectionType{
		if(ConnectionType::$VANILLA === null) {
			ConnectionType::$VANILLA = new ConnectionType(0, 0, "Vanilla", null, null, null, true);
		}
		return ConnectionType::$VANILLA;
	}
	/** @var ConnectionType */
	private static $RAKLIB = null;
	
	public static function getRakLib() : ConnectionType{
		if(ConnectionType::$RAKLIB === null) {
			ConnectionType::$RAKLIB = new ConnectionType(-3302738621981308437, -6084673292449311602, "RakLib", "PHP", RakLib::VERSION);
		}
		return ConnectionType::$RAKLIB;
	}
	
	/** @var int */
	private $uuidMostSignificant;
	/** @var int */
	private $uuidLeastSignificant;
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
	
	public function __construct(int $uuidMostSignificant, int $uuidLeastSignificant, string $name, ?string $language, ?string $version, array $metadata = array(), bool $vanilla = false){
		$this->uuidMostSignificant = $uuidMostSignificant;
		$this->uuidLeastSignificant = $uuidLeastSignificant;
		$this->name = $name;
		$this->language = $language;
		$this->version = $version;
		$this->metadata = $metadata;
		$this->vanilla = $vanilla;
	}
	
	public function getUUIDMostSignificant() : int{
		return $this->uuidMostSignificant;
	}
	
	public function getUUIDLeastSignificant() : int{
		return $this->uuidLeastSignificant;
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
		return $this->metadata[$key];
	}
	
	public function getMetaDataArray() : array{
		return $this->metadata;
	}
	
	public function isVanilla() : bool{
		return $this->vanilla;
	}

}