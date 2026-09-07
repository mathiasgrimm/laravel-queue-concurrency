<?php

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

// A link the failover queue hops off exactly as it would a dead redis, without
// needing a redis client: the connector for its driver does not exist.
beforeEach(function () {
    config()->set('cache.default', 'file');
    config()->set('queue.connections.dead', ['driver' => 'no-such-driver']);

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Cache::store('file')->flush();
});

afterEach(fn () => Cache::store('file')->flush());

function useChain(array $links): void
{
    config()->set('queue.connections.chain', ['driver' => 'failover', 'connections' => $links]);
    config()->set('queue.default', 'chain');
}

function runsSoFar(): int
{
    return (int) Cache::store('file')->get('runs', 0);
}

it('delivers the original exception when the chain falls through to sync', function () {
    useChain(['dead', 'sync']);

    try {
        Concurrency::driver('queue')->run([
            'only' => function () {
                Cache::store('file')->increment('runs');

                throw new DomainException('task failed');
            },
        ], timeout: 3);
    } catch (DomainException $e) {
        expect($e->getMessage())->toBe('task failed')
            ->and(runsSoFar())->toBe(1);

        return;
    }

    $this->fail('The original exception was not delivered.');
});

it('runs a failing task once even with another sync link after it', function () {
    useChain(['dead', 'sync', 'sync']);
    $hops = [];
    Event::listen(QueueFailedOver::class, function (QueueFailedOver $event) use (&$hops) {
        $hops[] = $event->connectionName;
    });

    expect(function () {
        Concurrency::driver('queue')->run([
            'only' => function () {
                Cache::store('file')->increment('runs');

                throw new DomainException('task failed');
            },
        ], timeout: 3);
    })->toThrow(DomainException::class);

    // The task's failure must not read as a dead link: the only hop is off "dead".
    expect(runsSoFar())->toBe(1)
        ->and($hops)->toBe(['dead']);
});

it('leaves no duplicate on a real queue after a sync link ran the task', function () {
    useChain(['dead', 'sync', 'database']);

    expect(function () {
        Concurrency::driver('queue')->run([
            'only' => function () {
                Cache::store('file')->increment('runs');

                throw new DomainException('task failed');
            },
        ], timeout: 3);
    })->toThrow(DomainException::class);

    expect(runsSoFar())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('does not let a dead link after sync mask the task exception', function () {
    useChain(['dead', 'sync', 'dead']);

    expect(function () {
        Concurrency::driver('queue')->run([
            'only' => fn () => throw new DomainException('task failed'),
        ], timeout: 3);
    })->toThrow(DomainException::class);
});

it('returns results when the chain falls through to sync', function () {
    useChain(['dead', 'sync']);

    expect(Concurrency::driver('queue')->run([
        'a' => fn () => 1 + 1,
        'b' => fn () => 'two',
    ], timeout: 3))->toBe(['a' => 2, 'b' => 'two']);
});

it('reports a sync fall through failure the way plain sync does', function () {
    useChain(['dead', 'sync']);
    Exceptions::fake();

    try {
        Concurrency::driver('queue')->run([
            'only' => fn () => throw new DomainException('task failed'),
        ], timeout: 3);
    } catch (DomainException) {
        //
    }

    Exceptions::assertReported(DomainException::class);
});

it('treats a chain made only of sync links as inline', function () {
    useChain(['sync']);
    config()->set('cache.default', 'array');

    expect(Concurrency::driver('queue')->run([fn () => 7]))->toBe([7]);
});

it('refuses a chain containing a connection that would never run the tasks', function (string $driver) {
    config()->set('queue.connections.never', ['driver' => $driver]);
    useChain(['dead', 'never']);

    Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
})->with(['null', 'deferred', 'background'])->throws(RuntimeException::class, 'may not be used with the queue concurrency driver');

it('refuses a cyclic failover chain', function () {
    config()->set('queue.connections.a', ['driver' => 'failover', 'connections' => ['b']]);
    config()->set('queue.connections.b', ['driver' => 'failover', 'connections' => ['a']]);
    config()->set('queue.default', 'a');

    Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
})->throws(RuntimeException::class, 'refers back to itself')->group('cyclic');

it('refuses a failover cache store whose fallback is not shared for an async run', function () {
    config()->set('queue.default', 'database');
    config()->set('cache.stores.fallback', ['driver' => 'failover', 'stores' => ['file', 'array']]);
    config()->set('cache.default', 'fallback');

    Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
})->throws(RuntimeException::class, 'is not shared across processes');

it('leaves a tombstone so a straggler refuses to run after the caller was answered', function () {
    config()->set('queue.default', 'sync');
    $invoked = false;

    $ulid = Str::freezeUlids(function () {
        return Concurrency::driver('queue')->run([fn () => 'done']);
    });

    expect(Cache::store('file')->get("illuminate:concurrency:{$ulid}:cancelled"))->toBeTrue();

    // The same job, redelivered after the run finished and its envelope was
    // deleted, with the deadline still in the future.
    $straggler = new InvokeQueuedClosure(
        "illuminate:concurrency:{$ulid}:0",
        "illuminate:concurrency:{$ulid}:cancelled",
        'file',
        60,
        time() + 60,
        true,
        new SerializableClosure(function () use (&$invoked) {
            $invoked = true;
        }),
    );

    $straggler->handle($this->app, $this->app->make(CacheFactory::class));

    expect($invoked)->toBeFalse();
});

it('skips a job whose envelope already exists', function () {
    $invoked = false;

    Cache::store('file')->put('dup:0', TaskResult::success('first'), 60);

    $job = new InvokeQueuedClosure('dup:0', 'dup:cancelled', 'file', 60, time() + 60, true, new SerializableClosure(function () use (&$invoked) {
        $invoked = true;
    }));

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    expect($invoked)->toBeFalse()
        ->and(TaskResult::unwrap(Cache::store('file')->get('dup:0')))->toBe('first');
});

it('runs a failing deferred task once when the chain falls through to sync', function () {
    useChain(['dead', 'sync', 'sync']);
    Exceptions::fake();

    Concurrency::driver('queue')->defer([
        function () {
            Cache::store('file')->increment('runs');

            throw new DomainException('deferred failed');
        },
    ])();

    expect(runsSoFar())->toBe(1);

    Exceptions::assertReported(DomainException::class);
});
