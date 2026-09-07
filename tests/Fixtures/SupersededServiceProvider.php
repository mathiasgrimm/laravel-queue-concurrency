<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Illuminate\Concurrency\ProcessDriver;
use MathiasGrimm\QueueConcurrency\QueueConcurrencyServiceProvider;

/**
 * Stands in for the day laravel/framework#61273 lands.
 *
 * Pointing the constant at a class that already exists is the same condition
 * the real provider sees once the framework ships Illuminate\Concurrency\QueueDriver,
 * without having to declare a class in the Illuminate namespace.
 */
class SupersededServiceProvider extends QueueConcurrencyServiceProvider
{
    public const FRAMEWORK_DRIVER = ProcessDriver::class;
}
