<?php

declare(strict_types=1);

namespace gameparrot\libpmcurl;

class CurlRequest {
	public function __construct(
		public string $url,
		/** @var array<string, string> */
		public array $headers = [],
		public int $timeout = 10,
		public array $extraOpts = [],
		public int $id = 0,
	) {
	}
}
