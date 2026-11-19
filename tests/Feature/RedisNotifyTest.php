<?php

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\SerializableClosure\SerializableClosure;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\Tests\RedisTestCase;

uses(RedisTestCase::class);

function redisNotifyJob(string $store): InvokeQueuedClosure
{
    $job = new InvokeQueuedClosure('task:0', 'task:cancelled', $store, 60, time() + 60, false, new SerializableClosure(fn () => 'value'));

    $job->notifyKey = 'task:notify';

    return $job;
}

function redisNotifyTokens(): array
{
    $store = Cache::store('redis')->getStore();

    return $store->connection()->lrange($store->getPrefix().'task:notify', 0, -1);
}

it('pushes a notify token once the result is stored', function () {
    redisNotifyJob('redis')->handle($this->app, $this->app->make(CacheFactory::class));

    $store = Cache::store('redis')->getStore();

    expect(Cache::store('redis')->get('task:0')['successful'])->toBeTrue()
        ->and(redisNotifyTokens())->toHaveCount(1)
        ->and($store->connection()->ttl($store->getPrefix().'task:notify'))->toBeGreaterThan(0);
});

it('pushes a notify token when the job fails', function () {
    redisNotifyJob('redis')->failed(new Exception('Worker failure'));

    expect(Cache::store('redis')->get('task:0')['successful'])->toBeFalse()
        ->and(redisNotifyTokens())->toHaveCount(1);
});

it('pushes nothing when the result store is not redis', function () {
    redisNotifyJob('array')->handle($this->app, $this->app->make(CacheFactory::class));

    expect(Cache::store('array')->get('task:0')['successful'])->toBeTrue()
        ->and(redisNotifyTokens())->toBeEmpty();
});
