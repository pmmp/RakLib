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

namespace raklib\protocol;

#include <rules/RakLibPacket.h>

class SERVER_HANDSHAKE_DataPacket extends Packet{
    public static $ID = 0x10;

    public $port;
    public $session;
    public $session2;

    public function encode(){
        parent::encode();
        $this->put("\x04\x3f\x57\xfe"); //cookie
        $this->put("\xcd"); //security flags
        $this->putShort($this->port);
        $this->putDataArray([
            "\xf5\xff\xff\xf5",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
            "\xff\xff\xff\xff",
        ]);
        $this->put("\x00\x00");
        $this->putLong($this->session);
        $this->putLong($this->session2);
    }

    public function decode(){
        parent::decode();
        //TODO, not needed yet
    }

    private function putDataArray(array $data = []){
        foreach($data as $v){
            $this->putTriad(strlen($v));
            $this->put($v);
        }
    }
}