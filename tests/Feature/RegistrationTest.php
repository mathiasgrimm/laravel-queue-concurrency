<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\QueueConcurrencyServiceProvider;
use MathiasGrimm\QueueConcurrency\QueueDriver;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

/**
 * Run one task through a faked bus and hand the dispatched job back. The run
 * itself always fails, because a faked bus never executes the job and so no
 * result is ever reported.
 */
function dispatchedJob(QueueDriver $driver): InvokeQueuedClosure
{
    $dispatched = null;

    try {
        $driver->run([fn () => 1]);
    } catch (RuntimeException) {
        //
    }

    Bus::assertDispatched(InvokeQueuedClosure::class, function ($job) use (&$dispatched) {
        $dispatched = $job;

        return true;
    });

    return $dispatched;
}

it('resolves the queue driver through the concurrency manager', function () {
    expect(Concurrency::driver('queue'))->toBeInstanceOf(QueueDriver::class);
});

it('applies the package config defaults', function () {
    config()->set('queue-concurrency.queue', 'default-instance');

    Bus::fake();

    expect(dispatchedJob(Concurrency::driver('queue'))->queue)->toBe('default-instance');
});

it('resolves a named instance declared under concurrency.drivers', function () {
    config()->set('concurrency.drivers.reports', ['driver' => 'queue', 'queue' => 'reports']);

    Bus::fake();

    $driver = Concurrency::driver('reports');

    expect($driver)->toBeInstanceOf(QueueDriver::class)
        ->and(dispatchedJob($driver)->queue)->toBe('reports');
});

it('resolves a named instance declared under the legacy concurrency.driver key', function () {
    config()->set('concurrency.driver', ['legacy' => ['driver' => 'queue', 'queue' => 'legacy-queue']]);

    Bus::fake();

    $driver = Concurrency::driver('legacy');

    expect($driver)->toBeInstanceOf(QueueDriver::class)
        ->and(dispatchedJob($driver)->queue)->toBe('legacy-queue');
});

it('resolves a named instance declared under queue-concurrency.instances', function () {
    config()->set('queue-concurrency.instances.exports', ['queue' => 'exports', 'timeout' => 120]);

    Bus::fake();

    $driver = Concurrency::driver('exports');

    expect($driver)->toBeInstanceOf(QueueDriver::class)
        ->and(dispatchedJob($driver)->queue)->toBe('exports');
});

it('returns new driver instances when targeting at runtime', function () {
    Bus::fake();

    $base = Concurrency::driver('queue');

    $configured = $base->onConnection('sync')->onQueue('images')->store('array');

    expect($configured)->toBeInstanceOf(QueueDriver::class)
        ->and($configured)->not->toBe($base);

    $job = dispatchedJob($configured);

    expect($job->connection)->toBe('sync')
        ->and($job->queue)->toBe('images')
        ->and($job->store)->toBe('array')
        ->and(dispatchedJob($base)->queue)->toBeNull();
});

it('is not superseded by the framework today', function () {
    expect(QueueConcurrencyServiceProvider::supersededByFramework())->toBeFalse();
});
