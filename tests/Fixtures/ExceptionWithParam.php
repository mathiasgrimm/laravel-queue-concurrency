<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Exception;

class ExceptionWithParam extends Exception
{
    public function __construct(
        public string $uri,
        public int $statusCode,
        public string $reason,
        public string|array $responseBody = '',
    ) {
        parent::__construct("API request to {$uri} failed with status $statusCode $reason");
    }
}
