<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ContractOnlyCacheRepository;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

// Every call the driver makes must be one the cache contract promises. The
// store here honours the contract and nothing more, so an off-contract call
// on the asynchronous path is a fatal rather than a slow test.
beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('cache.stores.contract', ['driver' => 'contract']);
    config()->set('cache.default', 'contract');
});

afterEach(fn () => Cache::store('file')->flush());

function bindContractOnlyStore(bool $generators): void
{
    Cache::extend('contract', fn ($app) => new ContractOnlyCacheRepository(Cache::store('file'), $generators));
}

it('collects results through a repository that only implements the contract', function (bool $generators) {
    bindContractOnlyStore($generators);
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
})->with(['array results' => [false], 'generator results' => [true]]);

it('polls through a repository that only implements the contract', function (bool $generators) {
    bindContractOnlyStore($generators);
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    Concurrency::driver('queue')->run([fn () => 1], timeout: 1);
})->with(['array results' => [false], 'generator results' => [true]])->throws(TaskTimedOutException::class);
