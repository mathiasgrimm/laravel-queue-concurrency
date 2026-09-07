<?php

use MathiasGrimm\QueueConcurrency\TaskResult;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithFalseyParam;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithoutConstructor;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithParam;
use MathiasGrimm\QueueConcurrency\Tests\Fixtures\ExceptionWithRequiredThrowable;

it('round trips values through a success envelope', function (mixed $value) {
    $envelope = TaskResult::success($value);

    expect($envelope['successful'])->toBeTrue()
        ->and(TaskResult::unwrap($envelope))->toEqual($value);
})->with([
    'string' => ['value'],
    'integer' => [42],
    'zero' => [0],
    'false' => [false],
    'null' => [null],
    'empty string' => [''],
    'array' => [['a' => 1, 'b' => [2, 3]]],
    'object' => [(object) ['name' => 'Taylor']],
]);

it('captures exception metadata in a failure envelope', function () {
    $envelope = TaskResult::failure(new RuntimeException('Something broke'));

    expect($envelope['successful'])->toBeFalse()
        ->and($envelope['exception'])->toBe(RuntimeException::class)
        ->and($envelope['message'])->toBe('Something broke')
        ->and($envelope['file'])->toBe(__FILE__)
        ->and($envelope['line'])->toBeInt()
        ->and($envelope['parameters'])->toBe([]);
});

it('rethrows failures using the message', function () {
    TaskResult::unwrap(TaskResult::failure(new RuntimeException('Something broke')));
})->throws(RuntimeException::class, 'Something broke');

it('reconstructs exceptions with constructor parameters', function () {
    $envelope = TaskResult::failure(new ExceptionWithParam(
        'https://api.example.com', 400, 'Bad Request', 'Invalid payload',
    ));

    try {
        TaskResult::unwrap($envelope);
    } catch (ExceptionWithParam $e) {
        expect($e->uri)->toBe('https://api.example.com')
            ->and($e->statusCode)->toBe(400)
            ->and($e->reason)->toBe('Bad Request')
            ->and($e->responseBody)->toBe('Invalid payload');

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('preserves falsey constructor parameters', function (int|bool|string $value) {
    $envelope = TaskResult::failure(new ExceptionWithFalseyParam($value));

    try {
        TaskResult::unwrap($envelope);
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

it('falls back when the constructor cannot be satisfied', function () {
    $envelope = TaskResult::failure(new ExceptionWithRequiredThrowable(
        'Query failed', new RuntimeException('The underlying failure'),
    ));

    try {
        TaskResult::unwrap($envelope);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe(ExceptionWithRequiredThrowable::class.': Query failed');

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('falls back when the exception class does not exist', function () {
    $envelope = TaskResult::failure(new RuntimeException('Original message'));

    $envelope['exception'] = 'App\\Exceptions\\DoesNotExist';

    try {
        TaskResult::unwrap($envelope);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('App\\Exceptions\\DoesNotExist: Original message');

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('tolerates envelopes without parameters', function () {
    $envelope = TaskResult::failure(new RuntimeException('Original message'));

    unset($envelope['parameters']);

    try {
        TaskResult::unwrap($envelope);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Original message');

        return;
    }

    $this->fail('The expected exception was not thrown.');
});

it('ignores inherited constructors when capturing parameters', function () {
    $envelope = TaskResult::failure(new ExceptionWithoutConstructor('Inherited'));

    expect($envelope['parameters'])->toBe([]);

    TaskResult::unwrap($envelope);
})->throws(ExceptionWithoutConstructor::class, 'Inherited');
