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

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_DISCONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;

class Session{
    const STATE_UNCONNECTED = 0;
    const STATE_CONNECTING_1 = 1;
    const STATE_CONNECTING_2 = 2;
    const STATE_CONNECTED = 3;

    public static $WINDOW_SIZE = 1024;

    protected $messageIndex = 0;

    /** @var SessionManager */
    protected $sessionManager;
    protected $address;
    protected $port;
    protected $state = self::STATE_UNCONNECTED;
    protected $mtuSize = 548; //Min size
    protected $id = 0;
    protected $splitID = 0;

    protected $lastSeqNumber = 0;
    protected $sendSeqNumber = 0;

    protected $timeout;

    protected $lastUpdate;
    protected $startTime;

    protected $isActive;

    /** @var int[] */
    protected $ACKQueue = [];
    /** @var int[] */
    protected $NACKQueue = [];

    /** @var DataPacket[] */
    protected $recoveryQueue = [];

    /** @var int[][] */
    protected $needACK = [];

    /** @var DataPacket */
    protected $sendQueue;

    protected $windowStart;
    protected $receivedWindow = [];
    protected $lastWindowIndex = 0;
    protected $windowEnd;

    public function __construct(SessionManager $sessionManager, $address, $port){
        $this->sessionManager = $sessionManager;
        $this->address = $address;
        $this->port = $port;
        $this->sendQueue = new DATA_PACKET_4();
        $this->lastUpdate = microtime(true);
        $this->startTime = microtime(true);
        $this->isActive = false;
        $this->windowStart = -(self::$WINDOW_SIZE / 2);
        $this->windowEnd = self::$WINDOW_SIZE / 2;
    }

    public function getAddress(){
        return $this->address;
    }

    public function getPort(){
        return $this->port;
    }

    public function getID(){
        return $this->id;
    }

    public function update($time){
        if(!$this->isActive and ($this->lastUpdate + 10) < $time){
            $this->disconnect("timeout");

            return;
        }
        $this->isActive = false;

        if(count($this->ACKQueue) > 0){
            $pk = new ACK();
            $pk->packets = $this->ACKQueue;
            $this->sendPacket($pk);
            $this->ACKQueue = [];
        }

        if(count($this->NACKQueue) > 0){
            $pk = new NACK();
            $pk->packets = $this->NACKQueue;
            $this->sendPacket($pk);
            $this->NACKQueue = [];
        }

        foreach($this->needACK as $identifierACK => $indexes){
            if(count($indexes) === 0){
                unset($this->needACK[$identifierACK]);
                $this->sessionManager->notifyACK($this, $identifierACK);
            }
        }

        if(count($this->recoveryQueue) > 0){
            $timeLimit = microtime(true) - 1.5;
            foreach($this->recoveryQueue as $key => $packet){
                if($packet->sendTime === null){
                    unset($this->recoveryQueue[$key]);
                }elseif($packet->sendTime < $timeLimit){
                    $this->sendPacket($packet);
                    $packet->sendTime = null;
                }
            }
        }

        foreach($this->receivedWindow as $seq => $bool){
            if($seq < $this->windowStart){
                unset($this->receivedWindow[$seq]);
            }else{
                break;
            }
        }

        $this->sendQueue();
    }

    public function disconnect($reason = "unknown"){
        $this->sessionManager->removeSession($this, $reason);
    }

    public function needUpdate(){
        return count($this->ACKQueue) > 0 or count($this->NACKQueue) > 0 or count($this->sendQueue->packets) > 0 or !$this->isActive;
    }

    protected function sendPacket(Packet $packet){
        $this->sessionManager->sendPacket($packet, $this->address, $this->port);
    }

    public function sendQueue(){
        if(count($this->sendQueue->packets) > 0){
            $this->sendQueue->seqNumber = $this->sendSeqNumber++;
            $this->sendPacket($this->sendQueue);
            $this->sendQueue->sendTime = microtime(true);
            $this->recoveryQueue[$this->sendQueue->seqNumber] = $this->sendQueue;
            $this->sendQueue = new DATA_PACKET_4();
        }
    }

    /**
     * @param EncapsulatedPacket $pk
     * @param int                $flags
     */
    protected function addToQueue(EncapsulatedPacket $pk, $flags = RakLib::PRIORITY_NORMAL){
        $priority = $flags & 0b0000111;
        if($pk->needACK and $pk->messageIndex !== null){
            $this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
        }
        if($priority === RakLib::PRIORITY_IMMEDIATE){ //Skip queues
            $packet = new DATA_PACKET_0();
            $packet->seqNumber = $this->sendSeqNumber++;
            $packet->packets[] = $pk;
            $this->sendPacket($packet);
            $packet->sendTime = microtime(true);
            $this->recoveryQueue[$packet->seqNumber] = $packet;

            return;
        }
        $length = $this->sendQueue->length();
        if($length + $pk->getTotalLength() > $this->mtuSize){
            $this->sendQueue();
        }
        $this->sendQueue->packets[] = $pk;
    }

    /**
     * @param EncapsulatedPacket $packet
     * @param int                $flags
     */
    public function addEncapsulatedToQueue(EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){

        $packet->needACK = ($flags & RakLib::FLAG_NEED_ACK) > 0;
        $this->needACK[$packet->identifierACK] = [];

        if($packet->getTotalLength() + 4 > $this->mtuSize){
            $packet->hasSplit = true;
            $buffers = str_split($packet->buffer, $this->mtuSize - 34);
            $packet->buffer = "";
            $packet->splitID = $packet->splitIndex = ++$this->splitID % 65536;
            $packet->splitCount = count($buffers);
            $packet->reliability = 2;
            foreach($buffers as $count => $buffer){
                $pk = clone $packet;
                $pk->splitIndex = $count;
                $pk->buffer = $buffer;
                $pk->messageIndex = $this->messageIndex++;
                $this->addToQueue($pk, $flags);
            }
        }else{
            if(
                $packet->reliability === 2 or
                $packet->reliability === 4 or
                $packet->reliability === 6 or
                $packet->reliability === 7
            ){
                $packet->messageIndex = $this->messageIndex++;
            }
            $this->addToQueue($packet, $flags);
        }
    }

    protected function handleEncapsulatedPacket(EncapsulatedPacket $packet){

        if($packet->messageIndex !== null){
            if($packet->messageIndex < $this->windowStart or $packet->messageIndex > $this->windowEnd or isset($this->receivedWindow[$packet->messageIndex])){
                return;
            }
            $diff = $packet->messageIndex - $this->lastWindowIndex;
            $this->receivedWindow[$packet->messageIndex] = true;

            if($diff >= 1){
                $this->lastWindowIndex = $packet->messageIndex;
                $this->windowStart += $diff;
                $this->windowEnd += $diff;
            }
        }

        $id = ord($packet->buffer{0});
        if($id < 0x80){ //internal data packet
            if($this->state === self::STATE_CONNECTING_2){
                if($id === CLIENT_CONNECT_DataPacket::$ID){
                    $dataPacket = new CLIENT_CONNECT_DataPacket;
                    $dataPacket->buffer = $packet->buffer;
                    $dataPacket->decode();
                    $pk = new SERVER_HANDSHAKE_DataPacket;
                    $pk->port = $this->port;
                    $pk->session = $dataPacket->session;
                    $pk->session2 = Binary::readLong("\x00\x00\x00\x00\x04\x44\x0b\xa9");
                    $pk->encode();

                    $sendPacket = new EncapsulatedPacket();
                    $sendPacket->reliability = 0;
                    $sendPacket->buffer = $pk->buffer;
                    $this->addToQueue($sendPacket, RakLib::PRIORITY_IMMEDIATE);
                }elseif($id === CLIENT_HANDSHAKE_DataPacket::$ID){
                    $dataPacket = new CLIENT_HANDSHAKE_DataPacket;
                    $dataPacket->buffer = $packet->buffer;
                    $dataPacket->decode();

                    if($dataPacket->port === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
                        $this->state = self::STATE_CONNECTED; //FINALLY!
                        $this->sessionManager->openSession($this);
                    }
                }
            }elseif($id === CLIENT_DISCONNECT_DataPacket::$ID){
                $this->disconnect("client disconnect");
            }//TODO: add PING/PONG (0x00/0x03) automatic latency measure
        }elseif($this->state === self::STATE_CONNECTED){
            $this->sessionManager->streamEncapsulated($this, $packet);
            //TODO: split packet handling
            //TODO: packet reordering
            //TODO: stream channels
        }
    }

    public function handlePacket(Packet $packet){
        $this->isActive = true;
        $this->lastUpdate = microtime(true);
        if($this->state === self::STATE_CONNECTED or $this->state === self::STATE_CONNECTING_2){
            if($packet::$ID >= 0x80 and $packet::$ID <= 0x8f and $packet instanceof DataPacket){ //Data packet
                $packet->decode();

                $diff = $packet->seqNumber - $this->lastSeqNumber;

                if($diff > self::$WINDOW_SIZE){
                    return;
                }elseif($diff > 1){
                    for($i = $this->lastSeqNumber + 1; $i < $packet->seqNumber; ++$i){
                        $this->NACKQueue[$i] = $i;
                    }
                }

                if($packet->seqNumber > $this->lastSeqNumber){
                    $this->lastSeqNumber = $packet->seqNumber;
                }
                unset($this->NACKQueue[$packet->seqNumber]);
                $this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;

                foreach($packet->packets as $pk){
                    $this->handleEncapsulatedPacket($pk);
                }
            }else{
                if($packet instanceof ACK){
                    $packet->decode();
                    foreach($packet->packets as $seq){
                        if(isset($this->recoveryQueue[$seq])){
                            foreach($this->recoveryQueue[$seq]->packets as $pk){
                                if($pk->needACK and $pk->messageIndex !== null){
                                    unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
                                }
                            }
                            unset($this->recoveryQueue[$seq]);
                        }
                    }
                }elseif($packet instanceof NACK){
                    $packet->decode();
                    foreach($packet->packets as $seq){
                        if(isset($this->recoveryQueue[$seq])){
                            $this->sendPacket($this->recoveryQueue[$seq]);
                        }
                    }
                }
            }

        }elseif($packet::$ID > 0x00 and $packet::$ID < 0x80){ //Not Data packet :)
            $packet->decode();
            if($packet instanceof UNCONNECTED_PING){
                $pk = new UNCONNECTED_PONG();
                $pk->serverID = $this->sessionManager->getID();
                $pk->pingID = $packet->pingID;
                $pk->serverName = $this->sessionManager->getName();
                $this->sendPacket($pk);
            }elseif($packet instanceof OPEN_CONNECTION_REQUEST_1){
                $packet->protocol; //TODO: check protocol number and refuse connections
                $pk = new OPEN_CONNECTION_REPLY_1;
                $pk->mtuSize = $packet->mtuSize;
                $pk->serverID = $this->sessionManager->getID();
                $this->sendPacket($pk);
                $this->state = self::STATE_CONNECTING_1;
            }elseif($this->state === self::STATE_CONNECTING_1 and $packet instanceof OPEN_CONNECTION_REQUEST_2){
                $this->id = $packet->clientID;
                if($packet->serverPort === $this->sessionManager->getPort() or !$this->sessionManager->portChecking){
                    $this->mtuSize = min($packet->mtuSize, 1464); //Max size, do not allow creating large buffers to fill server memory
                    $pk = new OPEN_CONNECTION_REPLY_2;
                    $pk->mtuSize = $this->mtuSize;
                    $pk->serverID = $this->sessionManager->getID();
                    $pk->clientPort = $this->port;
                    $this->sendPacket($pk);
                    $this->state = self::STATE_CONNECTING_2;
                }
            }
        }
    }
}