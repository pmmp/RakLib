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

declare(strict_types=1);

namespace raklib\server\ipc;

use pocketmine\utils\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\server\ipc\UserToRakLibThreadSessionMessageProtocol as ITCSessionProtocol;
use raklib\server\SessionInterface;
use function chr;

final class UserToRakLibThreadSessionMessageSender implements SessionInterface{

	private InterThreadChannelWriter $channel;

	public function __construct(InterThreadChannelWriter $channel){
		$this->channel = $channel;
	}

	public function sendEncapsulated(EncapsulatedPacket $packet, bool $immediate = false) : void{
		$flags =
			($immediate ? ITCSessionProtocol::ENCAPSULATED_FLAG_IMMEDIATE : 0) |
			($packet->identifierACK !== null ? ITCSessionProtocol::ENCAPSULATED_FLAG_NEED_ACK : 0);

		$buffer = chr(ITCSessionProtocol::PACKET_ENCAPSULATED) .
			chr($flags) .
			chr($packet->reliability) .
			($packet->identifierACK !== null ? Binary::writeInt($packet->identifierACK) : "") .
			(PacketReliability::isSequencedOrOrdered($packet->reliability) ? chr($packet->orderChannel) : "") .
			$packet->buffer;
		$this->channel->write($buffer);
	}

	public function initiateDisconnect(string $reason) : void{
		$this->channel->write(chr(ITCSessionProtocol::PACKET_CLOSE_SESSION));
	}
}
