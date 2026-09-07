<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Exception;

class ExceptionWithFalseyParam extends Exception
{
    public function __construct(public int|bool|string $value)
    {
        parent::__construct('Exception with falsey parameter');
    }
}
