<?php

use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ServiceProvider;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\SupersededServiceProvider;
use MathiasGrimm\QueueConcurrency\Tests\SupersededTestCase;

uses(SupersededTestCase::class);

it('reports that the framework supersedes it', function () {
    expect(SupersededServiceProvider::supersededByFramework())->toBeTrue();
});

it('registers no driver so the framework one wins', function () {
    Concurrency::driver('queue');
})->throws(InvalidArgumentException::class);

it('still merges its config', function () {
    expect(config('queue-concurrency.timeout'))->toBe(60);
});

it('still registers its config for publishing', function () {
    $paths = ServiceProvider::pathsToPublish(SupersededServiceProvider::class, 'queue-concurrency-config');

    expect($paths)->not->toBeEmpty()
        ->and(array_values($paths)[0])->toEndWith('config/queue-concurrency.php');
});
