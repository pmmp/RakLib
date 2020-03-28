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

namespace raklib\server;

use pocketmine\snooze\SleeperNotifier;
use raklib\generic\Socket;
use raklib\RakLib;
use raklib\utils\ExceptionTraceCleaner;
use raklib\utils\InternetAddress;
use function error_get_last;
use function error_reporting;
use function gc_enable;
use function getcwd;
use function ini_set;
use function mt_rand;
use function realpath;
use function register_shutdown_function;
use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;
use const PTHREADS_INHERIT_NONE;

class RakLibServer extends \Thread{
	/** @var InternetAddress */
	private $address;

	/** @var \ThreadedLogger */
	protected $logger;

	/** @var string */
	protected $loaderPath;

	/** @var bool */
	protected $shutdown = false;
	/** @var bool */
	protected $ready = false;

	/** @var \Threaded */
	protected $externalQueue;
	/** @var \Threaded */
	protected $internalQueue;

	/** @var string */
	protected $mainPath;

	/** @var int */
	protected $serverId = 0;
	/** @var int */
	protected $maxMtuSize;
	/** @var int */
	private $protocolVersion;

	/** @var SleeperNotifier */
	protected $mainThreadNotifier;

	/** @var \Throwable|null */
	public $crashInfo = null;

	/**
	 * @param \ThreadedLogger      $logger
	 * @param string               $autoloaderPath Path to Composer autoloader
	 * @param InternetAddress      $address
	 * @param int                  $maxMtuSize
	 * @param int|null             $overrideProtocolVersion Optional custom protocol version to use, defaults to current RakLib's protocol
	 * @param SleeperNotifier|null $sleeper
	 */
	public function __construct(\ThreadedLogger $logger, string $autoloaderPath, InternetAddress $address, int $maxMtuSize = 1492, ?int $overrideProtocolVersion = null, ?SleeperNotifier $sleeper = null){
		$this->address = $address;

		$this->serverId = mt_rand(0, PHP_INT_MAX);
		$this->maxMtuSize = $maxMtuSize;

		$this->logger = $logger;
		$this->loaderPath = $autoloaderPath;

		$this->externalQueue = new \Threaded;
		$this->internalQueue = new \Threaded;

		if(\Phar::running(true) !== ""){
			$this->mainPath = \Phar::running(true);
		}else{
			if(($cwd = getcwd()) === false or ($realCwd = realpath($cwd)) === false){
				throw new \RuntimeException("Failed to get current working directory");
			}
			$this->mainPath = $realCwd . DIRECTORY_SEPARATOR;
		}

		$this->protocolVersion = $overrideProtocolVersion ?? RakLib::DEFAULT_PROTOCOL_VERSION;

		$this->mainThreadNotifier = $sleeper;
	}

	public function isShutdown() : bool{
		return $this->shutdown === true;
	}

	public function shutdown() : void{
		$this->shutdown = true;
	}

	/**
	 * Returns the RakNet server ID
	 * @return int
	 */
	public function getServerId() : int{
		return $this->serverId;
	}

	public function getProtocolVersion() : int{
		return $this->protocolVersion;
	}

	/**
	 * @return \ThreadedLogger
	 */
	public function getLogger() : \ThreadedLogger{
		return $this->logger;
	}

	/**
	 * @return \Threaded
	 */
	public function getExternalQueue() : \Threaded{
		return $this->externalQueue;
	}

	/**
	 * @return \Threaded
	 */
	public function getInternalQueue() : \Threaded{
		return $this->internalQueue;
	}

	/**
	 * @return void
	 */
	public function shutdownHandler(){
		if($this->shutdown !== true){
			$error = error_get_last();

			if($error !== null){
				$this->logger->emergency("Fatal error: " . $error["message"] . " in " . $error["file"] . " on line " . $error["line"]);
				$this->setCrashInfo(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
			}else{
				$this->logger->emergency("RakLib shutdown unexpectedly");
			}
		}
	}

	public function getCrashInfo() : ?\Throwable{
		return $this->crashInfo;
	}

	private function setCrashInfo(\Throwable $e) : void{
		$this->synchronized(function(\Throwable $e) : void{
			$this->crashInfo = $e;
			$this->notify();
		}, $e);
	}

	public function startAndWait(int $options = PTHREADS_INHERIT_NONE) : void{
		$this->start($options);
		$this->synchronized(function() : void{
			while(!$this->ready and $this->crashInfo === null){
				$this->wait();
			}
			if($this->crashInfo !== null){
				throw $this->crashInfo;
			}
		});
	}

	public function run() : void{
		try{
			require $this->loaderPath;

			gc_enable();
			error_reporting(-1);
			ini_set("display_errors", '1');
			ini_set("display_startup_errors", '1');

			\ErrorUtils::setErrorExceptionHandler();
			register_shutdown_function([$this, "shutdownHandler"]);

			$socket = new Socket($this->address);
			$manager = new SessionManager(
				$this->serverId,
				$this->logger,
				$socket,
				$this->maxMtuSize,
				$this->protocolVersion,
				new InterThreadChannelReader($this->internalQueue),
				new InterThreadChannelWriter($this->externalQueue, $this->mainThreadNotifier),
				new ExceptionTraceCleaner($this->mainPath)
			);
			$this->synchronized(function() : void{
				$this->ready = true;
				$this->notify();
			});
			$manager->run();
		}catch(\Throwable $e){
			$this->setCrashInfo($e);
			$this->logger->logException($e);
		}
	}

}
