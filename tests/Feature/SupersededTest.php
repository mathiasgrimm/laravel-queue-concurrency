<?php

use Illuminate\Support\Facades\Concurrency;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\SupersededServiceProvider;
use MathiasGrimm\QueueConcurrency\Tests\SupersededTestCase;

uses(SupersededTestCase::class);

it('reports that the framework supersedes it', function () {
    expect(SupersededServiceProvider::supersededByFramework())->toBeTrue();
});

it('registers no driver so the framework one wins', function () {
    Concurrency::driver('queue');
})->throws(InvalidArgumentException::class);

it('still publishes its config', function () {
    expect(config('queue-concurrency.timeout'))->toBe(60);
});
