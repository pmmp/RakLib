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


class RakLibServer extends \Thread{
    protected $port;
    protected $interface;
    /** @var \ThreadedLogger */
    protected $logger;
    protected $loader;

    public $loadPaths = [];

    protected $shutdown;

    /** @var \Threaded */
    protected $externalQueue;
    /** @var \Threaded */
    protected $internalQueue;

    protected $externalSocket;
    protected $internalSocket;

	/**
	 * @param \Threaded       $externalThreaded
	 * @param \Threaded       $internalThreaded
	 * @param \ThreadedLogger $logger
	 * @param \ClassLoader    $loader
	 * @param int             $port
	 * @param string          $interface
	 *
	 * @throws \Exception
	 */
    public function __construct(\Threaded $externalThreaded, \Threaded $internalThreaded, \ThreadedLogger $logger, \ClassLoader $loader, $port, $interface = "0.0.0.0"){
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

        $this->externalQueue = $externalThreaded;
        $this->internalQueue = $internalThreaded;

        $sockets = [];
        if(!socket_create_pair((strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? AF_INET : AF_UNIX), SOCK_STREAM, 0, $sockets)){
            throw new \Exception("Could not create IPC sockets. Reason: " . socket_strerror(socket_last_error()));
        }

        $this->internalSocket = $sockets[0];
        socket_set_nonblock($this->internalSocket);
        $this->externalSocket = $sockets[1];
        socket_set_nonblock($this->externalSocket);

        $this->start();
    }

    protected function addDependency(array &$loadPaths, \ReflectionClass $dep){
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
        $this->lock();
        $this->shutdown = true;
        $this->unlock();
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

    /**
     * @return \Threaded
     */
    public function getExternalQueue(){
        return $this->externalQueue;
    }

    /**
     * @return \Threaded
     */
    public function getInternalQueue(){
        return $this->internalQueue;
    }

    public function getInternalSocket(){
        return $this->internalSocket;
    }

    public function pushMainToThreadPacket($str){
        $this->internalQueue[] = $str;
        socket_write($this->externalSocket, "\xff", 1); //Notify
    }

    public function readMainToThreadPacket(){
        return $this->internalQueue->shift();
    }

    public function pushThreadToMainPacket($str){
        $this->externalQueue[] = $str;
    }

    public function readThreadToMainPacket(){
        return $this->externalQueue->shift();
    }

    public function run(){
        //Load removed dependencies, can't use require_once()
        foreach($this->loadPaths as $name => $path){
            if(!class_exists($name, false) and !interface_exists($name, false)){
                require($path);
            }
        }
        $this->loader->register();

        $socket = new UDPServerSocket($this->getLogger(), $this->port, $this->interface);
        new SessionManager($this, $socket);
    }

}