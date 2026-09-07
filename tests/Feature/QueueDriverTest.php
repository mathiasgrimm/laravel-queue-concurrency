<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use MathiasGrimm\QueueConcurrency\InvokeDeferredClosure;
use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithFalseyParam;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithParam;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

it('returns results in order', function () {
    expect(Concurrency::driver('queue')->run([
        fn () => 1 + 1,
        fn () => 2 + 2,
    ]))->toBe([2, 4]);
});

it('preserves string keys', function () {
    expect(Concurrency::driver('queue')->run([
        'first' => fn () => 1 + 1,
        'second' => fn () => 2 + 2,
    ]))->toBe(['first' => 2, 'second' => 4]);
});

it('wraps a single closure', function () {
    expect(Concurrency::driver('queue')->run(fn () => 'value'))->toBe(['value']);
});

it('returns an empty array for no tasks', function () {
    expect(Concurrency::driver('queue')->run([]))->toBe([]);
});

it('preserves mixed keys in order', function () {
    expect(Concurrency::driver('queue')->run([
        5 => fn () => 'five',
        'a' => fn () => 'letter',
        0 => fn () => 'zero',
    ]))->toBe([5 => 'five', 'a' => 'letter', 0 => 'zero']);
});

it('accepts a CarbonInterval timeout', function () {
    expect(Concurrency::driver('queue')->run([fn () => 'value'], CarbonInterval::seconds(5)))->toBe(['value']);
});

it('rethrows a task exception in the caller', function () {
    Concurrency::driver('queue')->run([
        fn () => throw new Exception('This is a different exception'),
    ]);
})->throws(Exception::class, 'This is a different exception');

it('reconstructs a task exception with constructor parameters', function () {
    Concurrency::driver('queue')->run([
        fn () => throw new ExceptionWithParam('https://api.example.com', 400, 'Bad Request', 'Invalid payload'),
    ]);
})->throws(ExceptionWithParam::class, 'API request to https://api.example.com failed with status 400 Bad Request');

it('reconstructs a task exception with falsey constructor parameters', function (int|bool|string $value) {
    try {
        Concurrency::driver('queue')->run([
            fn () => throw new ExceptionWithFalseyParam($value),
        ]);
    } catch (ExceptionWithFalseyParam $e) {
        expect($e->value)->toBe($value);

        return;
    }

    $this->fail('The expected exception was not thrown.');
})->with([
    'zero' => [0],
    'false' => [false],
    'empty string' => [''],
]);

it('lets the first failure in key order win', function () {
    try {
        Concurrency::driver('queue')->run([
            'first' => fn () => throw new Exception('First failure'),
            'second' => fn () => throw new Exception('Second failure'),
        ]);
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('First failure');

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('cleans the cache up when a task fails', function () {
    $thrown = false;

    $ulid = Str::freezeUlids(function () use (&$thrown) {
        try {
            Concurrency::driver('queue')->run([
                'ok' => fn () => 'this task succeeds',
                'boom' => fn () => throw new RuntimeException('Task failure'),
            ]);
        } catch (RuntimeException) {
            $thrown = true;
        }
    });

    expect($thrown)->toBeTrue()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:0"))->toBeNull()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:1"))->toBeNull();
});

it('removes result keys from the cache after a run', function () {
    $ulid = Str::freezeUlids(function () {
        return Concurrency::driver('queue')->run([fn () => 1, fn () => 2]);
    });

    // The result keys go; the cancellation key stays as a tombstone so a
    // redelivered job refuses to run after the caller has been answered.
    expect(Cache::get("illuminate:concurrency:{$ulid}:0"))->toBeNull()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:1"))->toBeNull()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:cancelled"))->toBeTrue();
});

it('surfaces inline runs that exceed the timeout as task timeouts', function () {
    try {
        Concurrency::driver('queue')->run([
            'slow' => function () {
                Carbon::setTestNow(Carbon::now()->addSeconds(5));

                return 'finished';
            },
            'skipped' => fn () => 'never',
        ], timeout: 1);

        $this->fail('The expected timeout exception was not thrown.');
    } catch (TaskTimedOutException $e) {
        expect($e->received)->toBe(1)
            ->and($e->total)->toBe(2)
            ->and($e->seconds)->toBe(1)
            ->and($e->connection)->toBe('sync')
            ->and($e->store)->toBe('array');
    } finally {
        Carbon::setTestNow();
    }
});

it('collects inline envelopes before later tasks run', function () {
    $results = null;

    Str::freezeUlids(function ($ulid) use (&$results) {
        $results = Concurrency::driver('queue')->run([
            'first' => fn () => 'kept',
            'second' => function () use ($ulid) {
                // Simulates the first envelope expiring while a later inline
                // task is still running, since inline execution is not
                // bounded by the run timeout.
                Cache::forget("illuminate:concurrency:{$ulid}:0");

                return 'second';
            },
        ]);
    });

    expect($results)->toBe(['first' => 'kept', 'second' => 'second']);
});

it('writes a cancellation flag and cleans up when a dispatch fails', function () {
    $context = new class {};

    $thrown = null;

    $ulid = Str::freezeUlids(function () use ($context, &$thrown) {
        try {
            Concurrency::driver('queue')->run([
                // The first task runs inline and caches its envelope before
                // the second task's dispatch fails to serialize.
                'ok' => fn () => 'collected',
                'boom' => fn () => $context,
            ]);
        } catch (Throwable $thrown) {
            //
        }
    });

    expect($thrown)->not->toBeNull()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:cancelled"))->toBeTrue()
        ->and(Cache::get("illuminate:concurrency:{$ulid}:0"))->toBeNull();
});

it('fails without sleeping when an inline envelope is missing', function () {
    Bus::fake();
    Sleep::fake();

    Str::freezeUlids(function ($ulid) {
        Cache::put("illuminate:concurrency:{$ulid}:0", TaskResult::success('collected'), 60);

        try {
            Concurrency::driver('queue')->run([fn () => 1, fn () => 2]);

            $this->fail('The expected exception was not thrown.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('did not report a result');
        }

        expect(Cache::get("illuminate:concurrency:{$ulid}:0"))->toBeNull();
    });

    Sleep::assertNeverSlept();
});

it('dispatches one deferred job per task when deferring', function () {
    Bus::fake();

    $callback = Concurrency::driver('queue')->defer([fn () => 1, fn () => 2]);

    Bus::assertNothingDispatched();

    $callback();

    Bus::assertDispatchedTimes(InvokeDeferredClosure::class, 2);
});

it('rejects process local cache stores for async queues', function (string $driver) {
    config()->set('queue.default', 'database');
    config()->set('cache.stores.local', ['driver' => $driver]);
    config()->set('cache.default', 'local');

    Concurrency::driver('queue')->run([fn () => 1]);
})->with([
    'array' => ['array'],
    'null' => ['null'],
    'session' => ['session'],
    'octane' => ['octane'],
    'apc' => ['apc'],
])->throws(RuntimeException::class, 'is not shared across processes');

it('rejects deferred queue connections', function () {
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.default', 'deferred');

    Concurrency::driver('queue')->run([fn () => 1]);
})->throws(RuntimeException::class, 'may not be used with the queue concurrency driver');

it('rejects null queue connections', function () {
    config()->set('queue.connections.discard', ['driver' => 'null']);
    config()->set('queue.default', 'discard');

    Concurrency::driver('queue')->run([fn () => 1]);
})->throws(RuntimeException::class, 'may not be used with the queue concurrency driver');

it('rejects background queue connections', function () {
    config()->set('queue.connections.later', ['driver' => 'background']);
    config()->set('queue.default', 'later');

    Concurrency::driver('queue')->run([fn () => 1]);
})->throws(RuntimeException::class, 'may not be used with the queue concurrency driver');

it('rejects null queue connections when deferring', function () {
    config()->set('queue.connections.discard', ['driver' => 'null']);

    Concurrency::driver('queue')->onConnection('discard')->defer([fn () => 1]);
})->throws(RuntimeException::class, 'may not be used with the queue concurrency driver');
