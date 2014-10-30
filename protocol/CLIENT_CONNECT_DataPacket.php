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

class CLIENT_CONNECT_DataPacket extends Packet{
    public static $ID = 0x09;

    public $clientID;
    public $session;
    public $unknown;

    public function encode(){
        parent::encode();
        $this->putLong($this->clientID);
        $this->putLong($this->session);
        $this->put("\x00");
    }

    public function decode(){
        parent::decode();
        $this->clientID = $this->getLong();
        $this->session = $this->getLong();
        $this->unknown = $this->get(1);
    }
}