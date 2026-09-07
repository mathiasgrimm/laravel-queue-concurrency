<?php

namespace MathiasGrimm\QueueConcurrency\Tests;

use MathiasGrimm\QueueConcurrency\Tests\Fixtures\SupersededServiceProvider;

class SupersededTestCase extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SupersededServiceProvider::class,
        ];
    }
}
