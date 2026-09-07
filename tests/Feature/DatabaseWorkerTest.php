<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use MathiasGrimm\QueueConcurrency\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('cache.default', 'file');
    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'testing');
    config()->set('queue.failed.table', 'failed_jobs');

    Schema::dropIfExists('jobs');
    Schema::dropIfExists('failed_jobs');

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

    // Every fake sleep the poll loop takes drains one real job off the
    // database queue, so the round trip runs deterministically in process
    // instead of needing a background worker.
    Sleep::fake(syncWithCarbon: true);

    Sleep::whenFakingSleep(function () {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->run();
    });
});

afterEach(function () {
    Cache::store('file')->flush();
});

it('round trips results through a real database worker', function () {
    $results = Concurrency::driver('queue')->run([
        'first' => fn () => 1 + 1,
        'second' => fn () => 'two',
    ], timeout: 30);

    expect($results)->toBe(['first' => 2, 'second' => 'two'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('round trips task failures through a real database worker', function () {
    try {
        Concurrency::driver('queue')->run([fn () => throw new Exception('Worker failure')], timeout: 30);

        $this->fail('The expected exception was not thrown.');
    } catch (Exception $e) {
        // The caller receives the original exception type, not the wrapper
        // that was rethrown so the worker would record a failed job.
        expect($e::class)->toBe(Exception::class)
            ->and($e->getMessage())->toBe('Worker failure');
    }

    expect(DB::table('failed_jobs')->count())->toBe(1);
});
