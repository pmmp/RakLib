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

use raklib\RakLib;

class RakLibServer extends \Thread{
	protected $port;
	protected $interface;
	/** @var \ThreadedLogger */
	protected $logger;
	protected $loader;

	public $loadPaths = [];

	protected $shutdown;

	/**
	 * @param \ThreadedLogger $logger
	 * @param \SplAutoloader  $loader
	 * @param int             $port 1-65536
	 * @param string          $interface
	 *
	 * @throws \Exception
	 */
	public function __construct(\ThreadedLogger $logger, \SplAutoloader $loader, $port, $interface = "0.0.0.0"){
		$this->port = (int) $port;
		if($port < 1 or $port > 65536){
			throw new \Exception("Invalid port range");
		}

		$this->interface = $interface;
		$this->logger = $logger;
		$this->loader = $loader;
		$loadPaths = [];
		$this->addDependency($loadPaths, new \ReflectionClass($logger));
		$this->addDependency($loadPaths, new \ReflectionClass($loader));
		$this->loadPaths = array_reverse($loadPaths);
		$this->shutdown = false;
		$this->start(PTHREADS_INHERIT_ALL & ~PTHREADS_INHERIT_CLASSES);
	}

	public function addDependency(array &$loadPaths, \ReflectionClass $dep){
		if($dep->getFileName() !== false){
			$loadPaths[$dep->getName()] = $dep->getFileName();
		}

		if($dep->getParentClass() instanceof \ReflectionClass){
			$this->addDependency($loadPaths, $dep->getParentClass());
		}

		foreach($dep->getInterfaces() as $interface){
			$this->addDependency($loadPaths, $interface);
		}
	}

	public function isShutdown(){
		return $this->shutdown === true;
	}

	public function shutdown(){
		$this->shutdown = true;
	}

	public function getPort(){
		return $this->port;
	}

	public function getInterface(){
		return $this->interface;
	}

	/**
	 * @return \ThreadedLogger
	 */
	public function getLogger(){
		return $this->logger;
	}

	public function run(){
		//Load removed dependencies, can't use require_once()
		foreach($this->loadPaths as $name => $path){
			if(!class_exists($name, false) and !class_exists($name, false)){
				require($path);
			}
		}
		$this->loader->register();

		$socket = new UDPServerSocket($this->getLogger(), $this->port, $this->interface);
		$sessionManager = new SessionManager($this, $socket);
	}

}