<?php

namespace MathiasGrimm\QueueConcurrency\Tests;

use Illuminate\Support\Facades\Cache;
use Throwable;

abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {
            $this->markTestSkipped('Redis is not reachable at '.config('database.redis.default.host').'.');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('cache.default', 'redis');

        // Each test flushes the cache database, so keep it away from the one a
        // local Redis is likely already using.
        $app['config']->set('database.redis.cache.database', '15');
    }
}
