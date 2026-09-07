<?php

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\SerializableClosure\SerializableClosure;
use MathiasGrimm\QueueConcurrency\CapturedTaskException;
use MathiasGrimm\QueueConcurrency\InvokeQueuedClosure;
use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithClosureParam;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

function makeJob(callable $task, bool $rethrowFailures = false, ?int $deadline = null): InvokeQueuedClosure
{
    return new InvokeQueuedClosure(
        'task:0',
        'task:cancelled',
        null,
        60,
        $deadline ?? time() + 60,
        $rethrowFailures,
        new SerializableClosure($task),
    );
}

it('writes a success envelope', function () {
    $job = makeJob(fn () => 'value');

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    $envelope = Cache::get('task:0');

    expect($envelope['successful'])->toBeTrue()
        ->and(TaskResult::unwrap($envelope))->toBe('value');
});

it('swallows failures when not rethrowing', function () {
    $job = makeJob(fn () => throw new Exception('Task failure'));

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    $envelope = Cache::get('task:0');

    expect($envelope['successful'])->toBeFalse()
        ->and($envelope['exception'])->toBe(Exception::class);
});

it('rethrows captured task exceptions when rethrowing', function () {
    $job = makeJob(fn () => throw new Exception('Task failure'), rethrowFailures: true);

    try {
        $job->handle($this->app, $this->app->make(CacheFactory::class));
    } catch (CapturedTaskException $e) {
        expect($e->getMessage())->toBe('Task failure')
            ->and($e->getPrevious()->getMessage())->toBe('Task failure')
            ->and(Cache::get('task:0')['successful'])->toBeFalse();

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('honors the cancellation flag', function () {
    $invoked = false;

    Cache::forever('task:cancelled', true);

    $job = makeJob(function () use (&$invoked) {
        $invoked = true;
    });

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    expect($invoked)->toBeFalse()
        ->and(Cache::get('task:0'))->toBeNull();
});

it('honors an expired deadline', function () {
    $invoked = false;

    $job = makeJob(function () use (&$invoked) {
        $invoked = true;
    }, deadline: time() - 10);

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    expect($invoked)->toBeFalse()
        ->and(Cache::get('task:0'))->toBeNull();
});

it('does not overwrite an existing envelope when handling', function () {
    Cache::put('task:0', TaskResult::success('first'), 60);

    $job = makeJob(fn () => 'second');

    $job->handle($this->app, $this->app->make(CacheFactory::class));

    expect(TaskResult::unwrap(Cache::get('task:0')))->toBe('first');
});

it('writes an infrastructure failure envelope when the job fails', function () {
    $job = makeJob(fn () => 'value');

    $job->failed(new RuntimeException('The worker died'));

    $envelope = Cache::get('task:0');

    expect($envelope['successful'])->toBeFalse()
        ->and($envelope['exception'])->toBe(RuntimeException::class)
        ->and($envelope['message'])->toBe('The worker died');
});

it('ignores captured task exceptions when the job fails', function () {
    $job = makeJob(fn () => 'value');

    $job->failed(new CapturedTaskException(new Exception('Already enveloped')));

    expect(Cache::get('task:0'))->toBeNull();
});

it('does not overwrite an existing envelope when the job fails', function () {
    Cache::put('task:0', TaskResult::success('kept'), 60);

    $job = makeJob(fn () => 'value');

    $job->failed(new RuntimeException('Late failure'));

    expect(TaskResult::unwrap(Cache::get('task:0')))->toBe('kept');
});

it('never retries, fails on timeout and does not wait for a commit', function () {
    $job = makeJob(fn () => 'value');

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->afterCommit)->toBeFalse();
});

it('drops unserializable exception parameters from the failure envelope', function () {
    $job = new InvokeQueuedClosure(
        'illuminate:concurrency:test:0',
        'illuminate:concurrency:test:cancelled',
        'file',
        60,
        time() + 60,
        false,
        new SerializableClosure(fn () => throw new ExceptionWithClosureParam(fn () => 'context')),
    );

    try {
        $job->handle($this->app, $this->app->make(CacheFactory::class));

        $envelope = Cache::store('file')->get('illuminate:concurrency:test:0');

        expect($envelope['successful'])->toBeFalse()
            ->and($envelope['exception'])->toBe(ExceptionWithClosureParam::class)
            ->and($envelope['parameters'])->toBe([]);
    } finally {
        Cache::store('file')->flush();
    }
});
