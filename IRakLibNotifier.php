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

/**
 * Implementations can pass RakLib threads a Threaded object implementing this interface to get notified when packets
 * arrive.
 */
interface IRakLibNotifier{

	/**
	 * Called when the RakLib thread pushes a packet into the queue for the main thread to read.
	 */
	public function sendRakLibNotification() : void;
}
