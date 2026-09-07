<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // An async connection with a shared store is what puts the driver on its
    // polling path. The queue is faked, so nothing ever writes a result and
    // the loop runs to its deadline.
    config()->set('queue.default', 'database');
    config()->set('cache.default', 'file');
});

afterEach(function () {
    Cache::store('file')->flush();
    Carbon::setTestNow();
});

it('times out when no worker processes the tasks', function () {
    Queue::fake();

    // Without Carbon-synced fake sleeps advancing the clock, the poll loop
    // could never reach its deadline, so catching the timeout exception
    // below also proves that the loop polled and slept.
    Sleep::fake(syncWithCarbon: true);

    try {
        Concurrency::driver('queue')->run([fn () => 1, fn () => 2], timeout: 2);

        $this->fail('The expected timeout exception was not thrown.');
    } catch (TaskTimedOutException $e) {
        expect($e->received)->toBe(0)
            ->and($e->total)->toBe(2)
            ->and($e->seconds)->toBe(2)
            ->and($e->connection)->toBe('database')
            ->and($e->store)->toBe('file')
            ->and($e->getMessage())->toBe(
                'Concurrency tasks dispatched to the [database] queue connection timed out after 2 seconds with [0/2] results received. Ensure queue workers are running and writing results to the [file] cache store.',
            );
    }
});

it('names the queue in the timeout message when one is specified', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    try {
        Concurrency::driver('queue')->onQueue('reports')->run([fn () => 1], timeout: 1);

        $this->fail('The expected timeout exception was not thrown.');
    } catch (TaskTimedOutException $e) {
        expect($e->queue)->toBe('reports')
            ->and($e->getMessage())->toBe(
                'Concurrency tasks dispatched to the [database] queue connection on the [reports] queue timed out after 1 seconds with [0/1] results received. Ensure queue workers are running and writing results to the [file] cache store.',
            );
    }
});

it('writes a cancellation flag that outlives the run', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    $ulid = Str::freezeUlids(function () {
        try {
            Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
        } catch (TaskTimedOutException) {
            //
        }
    });

    expect(Cache::store('file')->get("illuminate:concurrency:{$ulid}:cancelled"))->toBeTrue()
        ->and(Cache::store('file')->get("illuminate:concurrency:{$ulid}:0"))->toBeNull();
});

it('returns as soon as every envelope is present', function () {
    Queue::fake();
    Sleep::fake();

    $results = null;

    Str::freezeUlids(function ($ulid) use (&$results) {
        Cache::store('file')->put("illuminate:concurrency:{$ulid}:0", TaskResult::success('one'), 60);
        Cache::store('file')->put("illuminate:concurrency:{$ulid}:1", TaskResult::success('two'), 60);

        $results = Concurrency::driver('queue')->run([
            'a' => fn () => null,
            'b' => fn () => null,
        ]);
    });

    expect($results)->toBe(['a' => 'one', 'b' => 'two']);

    Sleep::assertNeverSlept();
});

it('reports received results and cleans up on a partial timeout', function () {
    Carbon::setTestNow(Carbon::now());
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    Str::freezeUlids(function ($ulid) {
        Cache::store('file')->put("illuminate:concurrency:{$ulid}:0", TaskResult::success('one'), 60);

        try {
            Concurrency::driver('queue')->run(['a' => fn () => null, 'b' => fn () => null], timeout: 1);

            $this->fail('The expected timeout exception was not thrown.');
        } catch (TaskTimedOutException $e) {
            expect($e->received)->toBe(1)->and($e->total)->toBe(2);
        }

        expect(Cache::store('file')->get("illuminate:concurrency:{$ulid}:0"))->toBeNull()
            ->and(Cache::store('file')->get("illuminate:concurrency:{$ulid}:cancelled"))->toBeTrue();
    });
});

it('derives every job deadline from the run start', function () {
    Carbon::setTestNow($now = Carbon::now());
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    try {
        Concurrency::driver('queue')->run([fn () => 1], timeout: 30);

        $this->fail('The expected timeout exception was not thrown.');
    } catch (TaskTimedOutException) {
        //
    }

    Queue::assertPushed(InvokeQueuedClosure::class, fn ($job) => $job->deadline === $now->getTimestamp() + 30);
});

it('uses the full timeout budget in the poll loop', function () {
    Carbon::setTestNow(Carbon::now());
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    try {
        Concurrency::driver('queue')->run([fn () => 1], timeout: 1);

        $this->fail('The expected timeout exception was not thrown.');
    } catch (TaskTimedOutException) {
        //
    }

    Sleep::assertSleptTimes(10);
});
