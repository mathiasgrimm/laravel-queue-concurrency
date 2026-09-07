<?php

namespace MathiasGrimm\QueueConcurrency\Tests;

use MathiasGrimm\QueueConcurrency\QueueConcurrencyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            QueueConcurrencyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Pin the in memory connection rather than inheriting whatever
        // DB_CONNECTION happens to be set, so a workbench build sitting in
        // vendor cannot leak its tables into the suite.
        $app['config']->set('database.default', 'testing');

        // The "sync" connection with the "array" store is the one combination
        // that runs a task end to end inside a single process, so it is the
        // default here and individual tests opt into the async path.
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }
}
