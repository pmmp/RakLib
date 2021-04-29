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

namespace raklib\server\ipc;

use pocketmine\utils\Binary;
use raklib\server\ipc\UserToRakLibThreadMessageProtocol as ITCProtocol;
use raklib\server\ServerEventSource;
use raklib\server\ServerInterface;
use raklib\server\SessionInterface;
use function ord;
use function substr;

final class UserToRakLibThreadMessageReceiver implements ServerEventSource{
	/** @var InterThreadChannelReader */
	private $channel;

	private InterThreadChannelReaderDeserializer $channelReaderDeserializer;

	/**
	 * @var SessionInterface[][]|UserToRakLibThreadSessionMessageReceiver[][]
	 * @phpstan-var array<int, array{UserToRakLibThreadSessionMessageReceiver, SessionInterface}>
	 */
	private array $sessionMap = [];

	public function __construct(InterThreadChannelReader $channel, InterThreadChannelReaderDeserializer $channelReaderDeserializer){
		$this->channel = $channel;
		$this->channelReaderDeserializer = $channelReaderDeserializer;
	}

	/**
	 * @phpstan-return \Generator<int, null, void, void>
	 */
	public function process(ServerInterface $server) : \Generator{
		do{
			$processed = false;
			if(($packet = $this->channel->read()) !== null){
				$id = ord($packet[0]);
				$offset = 1;
				if($id === ITCProtocol::PACKET_RAW){
					$len = ord($packet[$offset++]);
					$address = substr($packet, $offset, $len);
					$offset += $len;
					$port = Binary::readShort(substr($packet, $offset, 2));
					$offset += 2;
					$payload = substr($packet, $offset);
					$server->sendRaw($address, $port, $payload);
				}elseif($id === ITCProtocol::PACKET_SET_NAME){
					$server->setName(substr($packet, $offset));
				}elseif($id === ITCProtocol::PACKET_ENABLE_PORT_CHECK){
					$server->setPortCheck(true);
				}elseif($id === ITCProtocol::PACKET_DISABLE_PORT_CHECK){
					$server->setPortCheck(false);
				}elseif($id === ITCProtocol::PACKET_SET_PACKETS_PER_TICK_LIMIT){
					$limit = Binary::readLong(substr($packet, $offset, 8));
					$server->setPacketsPerTickLimit($limit);
				}elseif($id === ITCProtocol::PACKET_BLOCK_ADDRESS){
					$len = ord($packet[$offset++]);
					$address = substr($packet, $offset, $len);
					$offset += $len;
					$timeout = Binary::readInt(substr($packet, $offset, 4));
					$server->blockAddress($address, $timeout);
				}elseif($id === ITCProtocol::PACKET_UNBLOCK_ADDRESS){
					$len = ord($packet[$offset++]);
					$address = substr($packet, $offset, $len);
					$server->unblockAddress($address);
				}elseif($id === ITCProtocol::PACKET_RAW_FILTER){
					$pattern = substr($packet, $offset);
					$server->addRawPacketFilter($pattern);
				}elseif($id === ITCProtocol::PACKET_OPEN_SESSION_RESPONSE){
					$sessionId = Binary::readInt(substr($packet, $offset, 4));
					$offset += 4;
					$session = $server->getSession($sessionId);
					if($session !== null){
						$channelInfo = substr($packet, $offset);
						$channel = $this->channelReaderDeserializer->deserialize($channelInfo);
						if($channel !== null){
							$this->sessionMap[$sessionId] = [new UserToRakLibThreadSessionMessageReceiver($channel), $session];
						}
					}
				}

				$processed = true;
				yield;
			}

			foreach($this->sessionMap as $sessionId => [$receiver, $session]){
				try{
					if($receiver->process($session)){
						$processed = true;
						yield;
					}
				}finally{
					if($receiver->isClosed()){
						unset($this->sessionMap[$sessionId]);
					}
				}
			}
		}while($processed);
	}
}
