<?php

declare(strict_types=1);

namespace gameparrot\libpmcurl;

use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\Server;
use pocketmine\utils\InternetRequestResult;
use pocketmine\utils\SingletonTrait;
use function curl_strerror;
use function explode;
use function igbinary_serialize;
use function igbinary_unserialize;
use function is_array;
use function json_encode;
use function spl_object_id;
use function strtolower;
use function substr;
use function trim;

class Curl {
	use SingletonTrait;

	private static bool $packaged;
	public static function isPackaged() : bool {
		return self::$packaged;
	}
	public static function detectPackaged() : void {
		self::$packaged = __CLASS__ !== 'gameparrot\libpmcurl\Curl';
	}

	private CurlThread $thread;
	private PthreadsChannelReader $pthreadReader;
	private PthreadsChannelWriter $pthreadWriter;

	private array $requests = [];

	public function __construct() {
		self::detectPackaged();

		/** @phpstan-var ThreadSafeArray<int, string> $mainToThreadBuffer */
		$mainToThreadBuffer = new ThreadSafeArray();
		/** @phpstan-var ThreadSafeArray<int, string> $threadToMainBuffer */
		$threadToMainBuffer = new ThreadSafeArray();

		$this->pthreadReader = new PthreadsChannelReader($threadToMainBuffer);
		$this->pthreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);

		$sleeperEntry = Server::getInstance()->getTickSleeper()->addNotifier(function () : void {
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
		$request->id = spl_object_id($cb);
		$this->requests[$request->id] = $cb;
		$this->pthreadWriter->write(igbinary_serialize($request));
	}
}
