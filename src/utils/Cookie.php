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

 namespace raklib\utils;

 use raklib\utils\InternetAddress;
 use pocketmine\utils\Binary;
 use function mt_rand;
 use function crc32;
 

 final class Cookie{

	 /**
     * @var (string|int)[] $cookies
     */
	private static array $cookies = [];

    public static function get(InternetAddress $address) : int{
        if (isset(self::$cookies[$address->toString()])) {
            return self::$cookies[$address->toString()];
        }
        return 0;
    }

    public static function check(InternetAddress $address, int $cookie) : bool{
        $addressStr = $address->toString();

        if (isset(self::$cookies[$addressStr])) {
            if (self::$cookies[$addressStr] == $cookie) {
                // If it checks the Cookie, it means that it is in the OpenConnectionRequest2 phase and we can delete it from memory
                unset(self::$cookies[$addressStr]);
                return true;
            }
        } else {
            $e = new \Exception(); // can u fix that?
        }

        unset(self::$cookies[$addressStr]);
        return false;
    }

    public static function add(InternetAddress $address) : void{
        if (!isset(self::$cookies[$address->toString()])) {
            self::$cookies[$address->toString()] = self::generate($address);
        }
    }

    private static function generate(InternetAddress $address) : int{
        return crc32(Binary::writeLInt(mt_rand(0, 0xFFFFFFFF)) . Binary::writeLShort($address->getPort()) . $address->getIp());
    }
}
