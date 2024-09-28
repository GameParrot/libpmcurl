<?php

declare(strict_types=1);

namespace gameparrot\libpmcurl;

use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\InternetRequestResult;
use function curl_strerror;
use function explode;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function json_encode;
use function mt_rand;
use function strtolower;
use function substr;
use function trim;
use const PHP_INT_MAX;

class Curl {
	private static bool $packaged;
	public static function isPackaged() : bool {
		return self::$packaged;
	}
	public static function detectPackaged() : void {
		self::$packaged = __CLASS__ !== 'gameparrot\libpmcurl\Curl';
	}

	private static self $instance;
	public static function getInstance() : ?Curl {
		return self::$instance ?? null;
	}

	public static function register(PluginBase $plugin) : void {
		self::$instance = new self($plugin);
	}

	private CurlThread $thread;
	private PthreadsChannelReader $pthreadReader;
	private PthreadsChannelWriter $pthreadWriter;

	private array $requests = [];

	public function __construct(PluginBase $plugin) {
		self::detectPackaged();

		/** @phpstan-var ThreadSafeArray<int, string> $mainToThreadBuffer */
		$mainToThreadBuffer = new ThreadSafeArray();
		/** @phpstan-var ThreadSafeArray<int, string> $threadToMainBuffer */
		$threadToMainBuffer = new ThreadSafeArray();

		$this->pthreadReader = new PthreadsChannelReader($threadToMainBuffer);
		$this->pthreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);

		$sleeperEntry = $plugin->getServer()->getTickSleeper()->addNotifier(function () : void {
			while ($buf = $this->pthreadReader->read()) {
				/** @var CurlResult */
				$response = igbinary_unserialize($buf);

				if (!isset($this->requests[$response->id])) {
					return;
				}
				$cb = $this->requests[$response->id];
				if ($response->curlError !== CURLE_OK) {
					$cb(null, curl_strerror($response->curlError));
				} else {
					$content = $response->raw;
					$rawHeaders = substr($content, 0, $response->headerSize);
					$body = substr($content, $response->headerSize);
					$headers = [];
					foreach (explode("\r\n\r\n", $rawHeaders) as $rawHeaderGroup) {
						$headerGroup = [];
						foreach (explode("\r\n", $rawHeaderGroup) as $line) {
							$nameValue = explode(":", $line, 2);
							if (isset($nameValue[1])) {
								$headerGroup[trim(strtolower($nameValue[0]))] = trim($nameValue[1]);
							}
						}
						$headers[] = $headerGroup;
					}
					$result = new InternetRequestResult($headers, $body, $response->httpStatus);
					$cb($result, null);
					unset($this->requests[$response->id]);
				}
			}
		});

		$this->thread = new CurlThread($mainToThreadBuffer, $threadToMainBuffer, $sleeperEntry);
		$this->thread->start();
	}

	public function getRequest(string $url, \Closure $callback, array $headers = [], int $timeout = 10) : void {
		$this->customRequest(new CurlRequest($url, $headers, $timeout), $callback);
	}

	public function postRequest(string $url, \Closure $callback, array $headers = [], string|array $body = "", int $timeout = 10) : void {
		if (is_array($body)) {
			$body = json_encode($body);
			if (!isset($headers["Content-Type"])) {
				$headers["Content-Type"] = "application/json";
			}
		}
		$this->customRequest(new CurlRequest($url, $headers, $timeout, [
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $body
		]), $callback);
	}

	public function customRequest(CurlRequest $request, \Closure $cb) : void {
		$request->id = mt_rand(0, PHP_INT_MAX);
		$this->requests[$request->id] = $cb;
		$this->pthreadWriter->write(igbinary_serialize($request));
	}
}
