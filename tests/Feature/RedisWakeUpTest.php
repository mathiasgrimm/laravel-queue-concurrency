<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
use MathiasGrimm\QueueConcurrency\Tests\RedisTestCase;
use Symfony\Component\Uid\Ulid;

uses(RedisTestCase::class);

beforeEach(function () {
    if (config('database.redis.client') === 'phpredis' && version_compare(phpversion('redis'), '6.0', '<')) {
        $this->markTestSkipped('phpredis before 6 cannot block for less than a second.');
    }
});

// Built outside the test closure so it carries no test class scope, which a
// separate worker process could not load when it unserializes the task.
function redisWakeUpTask(): Closure
{
    return fn () => 42;
}

it('returns as soon as a worker stores the result instead of waiting out the poll interval', function () {
    config()->set('queue-concurrency.poll', 5000);
    config()->set('queue-concurrency.queue', 'redis-wake-up');

    $worker = Process::path(dirname(__DIR__, 2))
        ->env([
            // The worker must verify the closure's signature with the same key.
            'APP_KEY' => config('app.key'),
            'QUEUE_CONNECTION' => 'redis',
            'QUEUE_FAILED_DRIVER' => 'null',
            'CACHE_STORE' => 'redis',
            'REDIS_CACHE_DB' => config('database.redis.cache.database'),
        ])
        ->start(['php', 'vendor/bin/testbench', 'queue:work', 'redis', '--queue=redis-wake-up', '--max-jobs=1', '--sleep=0']);

    try {
        $startedAt = microtime(true);

        $results = Concurrency::driver('queue')->run(['answer' => redisWakeUpTask()], timeout: 30);

        // A sleeping caller would not look again until the 5 second poll is up.
        expect($results)->toBe(['answer' => 42])
            ->and(microtime(true) - $startedAt)->toBeLessThan(4.0);
    } finally {
        if ($worker->running()) {
            $worker->signal(SIGTERM);
        }
    }
});

it('never sleeps while waiting on a redis store', function () {
    Queue::fake();
    Sleep::fake();

    expect(fn () => Concurrency::driver('queue')->run([fn () => 1], timeout: 1))
        ->toThrow(TaskTimedOutException::class);

    Sleep::assertNeverSlept();
});

it('sleeps out the interval when redis refuses the blocking pop', function () {
    $runId = '01KAB0Q2WJ6VJ4Z3C4E8XG5M7N';

    Str::createUlidsUsing(fn () => new Ulid($runId));

    // A string under the run's notify key makes BLPOP fail with WRONGTYPE.
    $store = Cache::store('redis')->getStore();
    $store->connection()->set($store->getPrefix()."illuminate:concurrency:{$runId}:notify", 'not a list');

    config()->set('queue-concurrency.poll', 250);

    Carbon::setTestNow(Carbon::now());
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    $startedAt = microtime(true);

    try {
        expect(fn () => Concurrency::driver('queue')->run([fn () => 1], timeout: 1))
            ->toThrow(TaskTimedOutException::class);
    } finally {
        Carbon::setTestNow();
        Str::createUlidsNormally();
    }

    // A 1 second budget at 250ms polls, slept rather than spent blocking in Redis.
    Sleep::assertSleptTimes(4);
    expect(microtime(true) - $startedAt)->toBeLessThan(0.5);
});
