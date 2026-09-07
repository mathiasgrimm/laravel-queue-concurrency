<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Route;
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;

// A throwaway app for driving the driver by hand against a genuinely separate
// worker process, which no in-process test can do:
//
//     vendor/bin/testbench serve
//     vendor/bin/testbench queue:work --queue=default --tries=1
//
// Then walk the routes below. Every response is JSON.

Route::get('/', fn () => response()->json([
    'demos' => [
        '/demo' => 'Three two second tasks in parallel on queue workers.',
        '/demo-sync' => 'The same tasks on the sync connection, one after another.',
        '/demo-timeout' => 'Tasks sent to a queue nobody consumes, so the caller times out.',
        '/demo-defer' => 'Fire and forget. Check /demo-defer-status a few seconds later.',
        '/demo-benchmark' => 'The same three tasks on the sync, process and queue drivers.',
    ],
    'worker' => 'Run "vendor/bin/testbench queue:work --queue=default --tries=1" first.',
]));

// The parallel run. With workers listening, the wall time should sit just above
// the slowest task rather than the sum of all three.
Route::get('/demo', function () {
    $start = microtime(true);

    $results = Concurrency::driver('queue')->run([
        'thumbnail' => function () {
            sleep(2);

            return 'thumbnail done by worker pid '.getmypid();
        },
        'preview' => function () {
            sleep(2);

            return 'preview done by worker pid '.getmypid();
        },
        'optimized' => function () {
            sleep(2);

            return 'optimized done by worker pid '.getmypid();
        },
    ], timeout: 15);

    return response()->json([
        'connection' => config('queue.default'),
        'caller_pid' => getmypid(),
        'wall_time_seconds' => round(microtime(true) - $start, 2),
        'results' => $results,
    ]);
});

// Runtime targeting. The sync connection runs everything inline in this
// request, so no worker is needed, but the tasks run one after another.
Route::get('/demo-sync', function () {
    $start = microtime(true);

    $results = Concurrency::driver('queue')->onConnection('sync')->run([
        'thumbnail' => function () {
            sleep(2);

            return 'thumbnail done by pid '.getmypid();
        },
        'preview' => function () {
            sleep(2);

            return 'preview done by pid '.getmypid();
        },
        'optimized' => function () {
            sleep(2);

            return 'optimized done by pid '.getmypid();
        },
    ], timeout: 15);

    return response()->json([
        'connection' => 'sync (inline, wall time is the sum of every task)',
        'caller_pid' => getmypid(),
        'wall_time_seconds' => round(microtime(true) - $start, 2),
        'results' => $results,
    ]);
});

// What a timeout looks like. Nothing consumes this queue, so after three
// seconds the caller gets a TaskTimedOutException with the full diagnostic
// message. A cancellation flag is written, so a worker that picks the jobs
// up later will refuse to run them.
Route::get('/demo-timeout', function () {
    try {
        Concurrency::driver('queue')->onQueue('nobody-listens')->run([
            fn () => 'never collected',
            fn () => 'never collected',
        ], timeout: 3);
    } catch (TaskTimedOutException $e) {
        return response()->json([
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'received' => $e->received,
            'total' => $e->total,
        ], 500);
    }

    return response()->json(['error' => 'expected a timeout'], 500);
});

// The other half of the contract. defer() returns immediately and the tasks
// are dispatched after the response is sent. Results are never collected, so
// these tasks write cache markers instead.
Route::get('/demo-defer', function () {
    Concurrency::driver('queue')->defer([
        function () {
            sleep(2);

            Cache::put('demo:defer:one', 'ran at '.now()->toDateTimeString().' on pid '.getmypid(), 300);
        },
        function () {
            sleep(2);

            Cache::put('demo:defer:two', 'ran at '.now()->toDateTimeString().' on pid '.getmypid(), 300);
        },
    ]);

    return response()->json([
        'dispatched' => 'after this response is sent',
        'next' => 'Check /demo-defer-status after a few seconds.',
    ]);
});

Route::get('/demo-defer-status', fn () => response()->json([
    'one' => Cache::get('demo:defer:one', 'not run yet'),
    'two' => Cache::get('demo:defer:two', 'not run yet'),
]));

// The same tasks, three drivers, one contract: sequential inline, parallel
// local processes, and parallel queue workers. Compare the wall times.
Route::get('/demo-benchmark', function () {
    $tasks = fn () => [
        'resize' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
        'optimize' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
        'watermark' => function () {
            sleep(2);

            return 'pid '.getmypid();
        },
    ];

    $benchmark = [];

    foreach (['sync', 'process', 'queue'] as $driver) {
        $start = microtime(true);

        try {
            // Resolve the results before reading the wall time, so keep them
            // in a variable rather than inlining the call after the timing
            // key. The timeout is passed positionally on purpose: on Laravel
            // 12 the sync and process drivers declare no $timeout parameter,
            // and a named argument they do not know is a fatal Error, while
            // an extra positional argument is simply ignored.
            $results = Concurrency::driver($driver)->run($tasks(), 15);

            $benchmark[$driver] = [
                'wall_time_seconds' => round(microtime(true) - $start, 2),
                'results' => $results,
            ];
        } catch (TaskTimedOutException) {
            $benchmark[$driver] = [
                'wall_time_seconds' => round(microtime(true) - $start, 2),
                'error' => 'Timed out. Are queue workers running?',
            ];
        }
    }

    return response()->json(['caller_pid' => getmypid(), 'benchmark' => $benchmark]);
});
