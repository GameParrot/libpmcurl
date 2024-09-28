<?php

declare(strict_types=1);

namespace gameparrot\libpmcurl;

use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use function microtime;
use function time_sleep_until;

class CurlThread extends Thread {
	private const TPS = 200;
	private const TIME_PER_TICK = 1 / self::TPS;

	public function __construct(
		protected ThreadSafeArray $mainToThreadBuffer,
		protected ThreadSafeArray $threadToMainBuffer,
		protected SleeperHandlerEntry $sleeperEntry,
	) {
		if (!Curl::isPackaged()) {
			$cl = Server::getInstance()->getPluginManager()->getPlugin("DEVirion")->getVirionClassLoader();
			$this->setClassLoaders([Server::getInstance()->getLoader(), $cl]);
		}
	}

	public function onRun() : void {
		$sleeperNotifier = $this->sleeperEntry->createNotifier();
		$curlWorker = new CurlWorker(new PthreadsChannelReader($this->mainToThreadBuffer), new SnoozeAwarePthreadsChannelWriter($this->threadToMainBuffer, $sleeperNotifier));
		while (!$this->isKilled) {
			$start = microtime(true);
			$curlWorker->tick();
			$time = microtime(true) - $start;
			if ($time < self::TIME_PER_TICK) {
				@time_sleep_until(microtime(true) + self::TIME_PER_TICK);
			}
		}
	}
}
