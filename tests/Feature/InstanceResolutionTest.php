<?php

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Concurrency\SyncDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\QueueConcurrencyServiceProvider;
use MathiasGrimm\QueueConcurrency\QueueDriver;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

function firstDispatchedJob(QueueDriver $driver): InvokeQueuedClosure
{
    $dispatched = null;

    try {
        $driver->run([fn () => 1]);
    } catch (RuntimeException) {
        // A faked bus never runs the job, so no result is ever reported.
    }

    Bus::assertDispatched(InvokeQueuedClosure::class, function ($job) use (&$dispatched) {
        $dispatched ??= $job;

        return true;
    });

    return $dispatched;
}

// A legacy "concurrency.driver.<name>" entry is routed by the manager to the
// creator registered under its *driver* name, so the factory serving it is the
// one called "queue". It must not pick up the options of the instance that is
// literally called "queue".
it('does not leak the default instance options into a legacy instance', function () {
    config()->set('concurrency.drivers.queue', ['driver' => 'queue', 'queue' => 'queue-specific']);
    config()->set('concurrency.driver.reports', ['driver' => 'queue', 'queue' => 'reports']);

    Bus::fake();

    expect(firstDispatchedJob(Concurrency::driver('reports'))->queue)->toBe('reports');
});

it('lets concurrency.drivers win over queue-concurrency.instances for the same name', function () {
    config()->set('queue-concurrency.instances.reports', ['queue' => 'from-instances', 'timeout' => 30]);
    config()->set('concurrency.drivers.reports', ['driver' => 'queue', 'queue' => 'from-drivers']);

    Bus::fake();

    $job = firstDispatchedJob(Concurrency::driver('reports'));

    // The framework shaped source wins on the key both define, and the key
    // only one defines still comes through.
    expect($job->queue)->toBe('from-drivers')
        ->and($job->timeout)->toBe(30);
});

// A bare legacy entry is byte for byte the placeholder the manager invents for
// an unconfigured name, so the "queue" creator could never tell the two apart.
// Refusing it is what makes the placeholder branch in the factory unambiguous.
it('refuses a legacy instance that declares no options of its own', function () {
    config()->set('concurrency.driver.reports', ['driver' => 'queue']);

    Concurrency::driver('reports');
})->throws(InvalidArgumentException::class, 'concurrency.driver.reports');

it('does not let a legacy instance inherit the default instance overrides', function () {
    config()->set('queue-concurrency.queue', 'global');
    config()->set('concurrency.drivers.queue', ['driver' => 'queue', 'queue' => 'queue-specific']);
    config()->set('concurrency.driver.reports', ['driver' => 'queue', 'timeout' => 120]);

    Bus::fake();

    $job = firstDispatchedJob(Concurrency::driver('reports'));

    // Package defaults plus the entry's own options. The instance literally
    // called "queue" is a sibling, not a parent.
    expect($job->queue)->toBe('global')
        ->and($job->timeout)->toBe(120);
});

it('clears the facade cache when a refused config evicts the manager', function () {
    // A manager the facade already holds before the provider boots, built
    // without any resolving callback firing, which is what an earlier
    // provider's Concurrency::driver() call leaves behind.
    Concurrency::swap(new ConcurrencyManager($this->app));

    config()->set('queue-concurrency.instances.sync', ['queue' => 'hijacked']);

    expect(fn () => (new QueueConcurrencyServiceProvider($this->app))->boot())
        ->toThrow(InvalidArgumentException::class);

    config()->set('queue-concurrency.instances', []);

    // Through the facade, which is where the stale manager would hide.
    expect(Concurrency::driver('queue'))->toBeInstanceOf(QueueDriver::class);
});

it('re-validates on the next resolution after a guard failure', function () {
    config()->set('queue-concurrency.instances.sync', ['queue' => 'hijacked']);

    try {
        Concurrency::driver('sync');

        $this->fail('The reserved name should have been refused.');
    } catch (InvalidArgumentException) {
        //
    }

    // The container had already cached the manager when the guard threw. It
    // must not hand that half initialised singleton out on the next call.
    expect(fn () => Concurrency::driver('sync'))->toThrow(InvalidArgumentException::class);

    config()->set('queue-concurrency.instances', []);

    expect(Concurrency::driver('queue'))->toBeInstanceOf(QueueDriver::class)
        ->and(Concurrency::driver('sync'))->toBeInstanceOf(SyncDriver::class);
});

it('refuses a legacy instance whose options are split across another config source', function () {
    // An option of its own, so this gets past the bare entry rule and reaches
    // the split rule; the message asserted is the split rule's.
    config()->set('concurrency.driver.reports', ['driver' => 'queue', 'timeout' => 120]);
    config()->set('queue-concurrency.instances.reports', ['queue' => 'reports']);

    Concurrency::driver('reports');
})->throws(InvalidArgumentException::class, 'also has options under');

it('refuses an instance named after a driver the manager already provides', function (string $name) {
    config()->set('queue-concurrency.instances.'.$name, ['queue' => 'hijacked']);

    Concurrency::driver($name);
})->with(['process', 'sync', 'fork'])->throws(InvalidArgumentException::class, 'reserved');

it('registers even when the manager was resolved before the provider booted', function () {
    // Stand in for a provider that touched the manager first. instance() marks
    // the binding resolved without firing any resolving callbacks, which is
    // exactly the state an earlier resolution leaves behind.
    $this->app->forgetInstance(ConcurrencyManager::class);
    $this->app->instance(ConcurrencyManager::class, $manager = new ConcurrencyManager($this->app));

    (new QueueConcurrencyServiceProvider($this->app))->boot();

    expect($manager->driver('queue'))->toBeInstanceOf(QueueDriver::class);
});

it('is the default driver when concurrency.default is queue', function () {
    config()->set('concurrency.default', 'queue');

    expect(Concurrency::run([fn () => 1 + 1]))->toBe([2]);
});

// The configured timeout, ttl and poll options must actually reach the driver.
// Ignoring any of them stayed green before these tests existed.
it('applies the configured timeout to each dispatched job', function () {
    config()->set('queue-concurrency.timeout', 7);

    Bus::fake();

    expect(firstDispatchedJob(Concurrency::driver('queue'))->timeout)->toBe(7);
});

it('applies a per instance timeout', function () {
    config()->set('queue-concurrency.instances.exports', ['queue' => 'exports', 'timeout' => 120]);

    Bus::fake();

    $job = firstDispatchedJob(Concurrency::driver('exports'));

    expect($job->queue)->toBe('exports')
        ->and($job->timeout)->toBe(120);
});

it('applies the configured ttl, never below timeout plus sixty seconds', function () {
    config()->set('queue-concurrency.timeout', 10);
    config()->set('queue-concurrency.ttl', 600);

    Bus::fake();

    expect(firstDispatchedJob(Concurrency::driver('queue'))->ttl)->toBe(600);

    config()->set('queue-concurrency.ttl', 5);
    Concurrency::forgetInstance('queue');
    Bus::fake();

    expect(firstDispatchedJob(Concurrency::driver('queue'))->ttl)->toBe(70);
});

it('applies the configured poll interval', function () {
    config()->set('queue.default', 'database');
    config()->set('cache.default', 'file');
    config()->set('queue-concurrency.poll', 250);

    // Freeze the clock so the only time that passes is the faked sleeps.
    // Otherwise the microseconds between run start and the first tick leave
    // the last sleep clamped short and a fifth, tiny sleep follows.
    Carbon::setTestNow(Carbon::now());
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    try {
        Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
    } catch (TaskTimedOutException) {
        //
    } finally {
        Carbon::setTestNow();
    }

    // One second at 250ms per poll is four sleeps, not the ten a 100ms poll takes.
    Sleep::assertSleptTimes(4);
});
