<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MathiasGrimm\QueueConcurrency\InvokeDeferredClosure;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

// defer() used to dispatch CallQueuedClosure. Its replacement must keep what
// users of defer() had: the worker decides retries, the closure can ask for
// the job, and a closure whose model is gone is discarded rather than failed.

beforeEach(function () {
    config()->set('cache.default', 'file');
    Cache::store('file')->flush();
});

afterEach(fn () => Cache::store('file')->flush());

function prepareDatabaseQueueForDeferred(): void
{
    config()->set('queue.default', 'database');
    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'testing');
    config()->set('queue.failed.table', 'failed_jobs');

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

it('lets the worker retry a deferred task that fails once', function () {
    prepareDatabaseQueueForDeferred();

    Concurrency::driver('queue')->defer([
        function () {
            if (Cache::store('file')->increment('attempts') === 1) {
                throw new RuntimeException('first attempt fails');
            }

            Cache::store('file')->put('outcome', 'succeeded on retry', 60);
        },
    ])();

    // Two attempts allowed by the worker, so the second one completes the task.
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 2, '--sleep' => 0])->run();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 2, '--sleep' => 0])->run();

    expect(Cache::store('file')->get('attempts'))->toBe(2)
        ->and(Cache::store('file')->get('outcome'))->toBe('succeeded on retry')
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('injects the job into a deferred closure that asks for it', function () {
    config()->set('queue.default', 'sync');

    // CallQueuedClosure hands the closure the queued command itself as $job,
    // which in turn carries the queue's job wrapper; keep exactly that.
    Concurrency::driver('queue')->defer([
        function ($job) {
            Cache::store('file')->put('job class', $job::class, 60);
            Cache::store('file')->put('wrapper class', $job->job::class, 60);
        },
    ])();

    expect(Cache::store('file')->get('job class'))->toBe(InvokeDeferredClosure::class)
        ->and(Cache::store('file')->get('wrapper class'))->toBe(SyncJob::class);
});

it('discards a deferred closure whose models are missing, as CallQueuedClosure did', function () {
    expect(property_exists(InvokeDeferredClosure::class, 'deleteWhenMissingModels'))->toBeTrue()
        ->and((new ReflectionProperty(InvokeDeferredClosure::class, 'deleteWhenMissingModels'))->getDefaultValue())->toBeTrue()
        // No $tries on the class: the worker's --tries applies, as it did before.
        ->and(property_exists(InvokeDeferredClosure::class, 'tries'))->toBeFalse();
});
