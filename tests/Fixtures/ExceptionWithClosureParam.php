<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Closure;
use Exception;

class ExceptionWithClosureParam extends Exception
{
    public function __construct(public Closure $callback)
    {
        parent::__construct('Exception with closure parameter');
    }
}
