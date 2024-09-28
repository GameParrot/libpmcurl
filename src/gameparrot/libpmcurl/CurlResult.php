<?php

declare(strict_types=1);

namespace gameparrot\libpmcurl;

class CurlResult {
	public function __construct(
		public int $id,
		public int $curlError,
		public int $httpStatus,
		public int $headerSize,
		public string $raw,
	) {
	}
}
