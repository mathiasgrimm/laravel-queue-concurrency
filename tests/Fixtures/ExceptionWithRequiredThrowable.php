<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Exception;
use Throwable;

class ExceptionWithRequiredThrowable extends Exception
{
    public function __construct(string $message, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
