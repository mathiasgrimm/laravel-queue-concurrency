<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection that concurrency tasks are dispatched to. When this
    | is null the application's default queue connection is used. The tasks
    | block the caller, so the connection must be consumed by a worker.
    |
    */

    'connection' => env('CONCURRENCY_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | The queue that concurrency tasks are pushed onto. When this is null the
    | connection's own default queue is used. Give the tasks a dedicated
    | queue if the workers that consume it also call Concurrency::run().
    |
    */

    'queue' => env('CONCURRENCY_QUEUE'),

    /*
    |--------------------------------------------------------------------------
    | Result Cache Store
    |--------------------------------------------------------------------------
    |
    | Task results travel back to the caller through this cache store, so it
    | must be shared between the caller and the workers. When this is null
    | the application's default store is used. Redis is a good choice.
    |
    */

    'store' => env('CONCURRENCY_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | How many seconds the caller will block waiting for every task to report
    | a result before a TaskTimedOutException is thrown. Work the client
    | should not wait for still belongs in an ordinary queued job.
    |
    */

    'timeout' => (int) env('CONCURRENCY_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Result Lifetime
    |--------------------------------------------------------------------------
    |
    | How many seconds a result envelope is kept in the cache. The effective
    | value is never lower than the timeout plus 60 seconds of grace, so a
    | late write from a worker cannot outlive the caller indefinitely.
    |
    */

    'ttl' => null,

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | How many milliseconds the caller waits between checks for results. The
    | final wait is clamped to the remaining budget, so this only controls
    | how often the cache is read, never how long the caller overshoots.
    |
    */

    'poll' => 100,

    /*
    |--------------------------------------------------------------------------
    | Named Instances
    |--------------------------------------------------------------------------
    |
    | Additional queue driver instances, keyed by the name you resolve them
    | with. Each entry overrides the options above, so a reports instance
    | may block for longer on its own queue than the default one does.
    |
    |     'instances' => [
    |         'reports' => ['queue' => 'reports', 'timeout' => 120],
    |     ],
    |
    | These are resolved with Concurrency::driver('reports'). A name may not be
    | one the concurrency manager already provides (process, sync, fork), and
    | an instance the manager reads from a legacy "concurrency.driver.<name>"
    | entry must keep all of its options there, since that entry is all the
    | manager will hand over.
    |
    */

    'instances' => [],

];
