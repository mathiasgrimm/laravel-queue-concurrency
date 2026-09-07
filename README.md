# Laravel Queue Concurrency

> A `queue` driver for Laravel's Concurrency component. The same blocking
> `Concurrency::run()`, but your closures run on queue workers.

<p align="left">
    <a href="https://packagist.org/packages/mathiasgrimm/laravel-queue-concurrency"><img src="https://img.shields.io/packagist/v/mathiasgrimm/laravel-queue-concurrency.svg?style=flat-square" alt="Latest Version on Packagist"></a>
    <a href="https://github.com/mathiasgrimm/laravel-queue-concurrency/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/mathiasgrimm/laravel-queue-concurrency/tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
    <a href="https://packagist.org/packages/mathiasgrimm/laravel-queue-concurrency"><img src="https://img.shields.io/packagist/dt/mathiasgrimm/laravel-queue-concurrency.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/mathiasgrimm/laravel-queue-concurrency"><img src="https://img.shields.io/packagist/l/mathiasgrimm/laravel-queue-concurrency.svg?style=flat-square" alt="License"></a>
</p>

> [!NOTE]
> This is an independent, community package. It is not an official or
> first-party Laravel package, and is not affiliated with, endorsed by, or
> sponsored by Laravel or Laravel Cloud. "Laravel" is a trademark of its
> respective owner.

Laravel's Concurrency component runs closures in parallel and hands you back
their results. Its `process` and `fork` drivers can only use the machine they
are called on, so the calling instance has to be big enough for everything it
spawns: a small web node that fans out three image transforms is suddenly
running four PHP processes.

**Laravel Queue Concurrency** adds a `queue` driver with the same contract. The
closures are dispatched as queued jobs, your workers execute them, and the
results travel back to the blocked caller through a shared cache store:

```php
$results = Concurrency::driver('queue')->run([
    'thumbnail' => fn () => ProcessImage::thumbnail($path),
    'preview' => fn () => ProcessImage::preview($path),
    'optimized' => fn () => ProcessImage::optimize($path),
]);
```

The web tier stays small and the heavy work runs on workers sized for it, in
parallel, so the response time is close to the slowest task instead of the sum
of all of them. It needs nothing your app does not already have: a queue and a
cache. No new tables, no migrations, no new services.

It pairs particularly well with scale-to-zero workers, such as [Laravel Cloud
managed queues](https://laravel.com/cloud/docs/queues#managed-queues). Workers
that wake in under a second and bill per second mean a burst of tasks is
absorbed by compute that exists only for the seconds it is needed. It also
makes it possible to keep an endpoint synchronous where today you would build a
submit-poll-webhook flow just because the work is too heavy for the web node.

## Features

- **The same contract as every other driver.** It blocks, keys and order are
  preserved, each task returns its value, and a task that throws has its
  exception rebuilt and rethrown in the caller.
- **Runs on your existing queue and cache.** No tables, no migrations, no
  extra services.
- **Runtime targeting.** Pick the connection, queue or result store per call
  with `onConnection()`, `onQueue()` and `store()`.
- **Bounded waits.** A configurable timeout, after which the caller gets a
  `TaskTimedOutException` naming exactly how many results arrived.
- **Cooperative cancellation.** A timed out run writes a cancellation flag, so
  a worker that picks the jobs up afterwards refuses to run them.
- **Failures stay visible.** A failing task still lands in `failed_jobs` and
  Horizon, while the caller receives the original exception type.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13

Laravel 11 works and is covered by the test suite, but it is end of life and
every 11.x release now carries an unpatched security advisory, so Composer's
advisory policy will refuse to install it unless you turn that policy off.
Laravel 12 and 13 install normally.

## Installation

```bash
composer require mathiasgrimm/laravel-queue-concurrency
```

The driver registers itself. Nothing else is required if your defaults already
point at a real queue and a shared cache:

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

To make it the application-wide default for a plain `Concurrency::run()`:

```dotenv
CONCURRENCY_DRIVER=queue
```

Publish the config only if you want to change something in it:

```bash
php artisan vendor:publish --tag=queue-concurrency-config
```

## Usage

Resolve the driver by name and call it exactly like any other:

```php
use Illuminate\Support\Facades\Concurrency;

$results = Concurrency::driver('queue')->run([
    'thumbnail' => fn () => ProcessImage::thumbnail($path),
    'preview' => fn () => ProcessImage::preview($path),
]);

// ['thumbnail' => ..., 'preview' => ...]
```

A per-call timeout, as seconds or a `CarbonInterval`:

```php
Concurrency::driver('queue')->run([...], timeout: 15);
```

Targeting at runtime. Each of these returns a new driver instance, so the one
held by the manager is never mutated:

```php
Concurrency::driver('queue')
    ->onConnection('redis')
    ->onQueue('images')
    ->store('redis')
    ->run([...]);
```

Fire and forget, which returns immediately and dispatches after the response is
sent. No results are collected:

```php
Concurrency::driver('queue')->defer([
    fn () => Report::rebuild(),
    fn () => Cache::forget('dashboard'),
]);
```

Handling a timeout:

```php
use MathiasGrimm\QueueConcurrency\TaskTimedOutException;

try {
    $results = Concurrency::driver('queue')->run([...], timeout: 10);
} catch (TaskTimedOutException $e) {
    // $e->received, $e->total, $e->seconds, $e->connection, $e->queue, $e->store
}
```

## Configuration

`config/queue-concurrency.php`:

| Option | Env | Default | Description |
| --- | --- | --- | --- |
| `connection` | `CONCURRENCY_QUEUE_CONNECTION` | `queue.default` | The queue connection tasks are dispatched to. |
| `queue` | `CONCURRENCY_QUEUE` | the connection's own default | The queue tasks are pushed onto. |
| `store` | `CONCURRENCY_CACHE_STORE` | `cache.default` | The cache store results travel back through. |
| `timeout` | `CONCURRENCY_TIMEOUT` | `60` | Seconds the caller blocks before throwing. |
| `ttl` | | `timeout + 60` | Seconds a result envelope is kept. Never lower than the timeout plus 60 seconds of grace. |
| `poll` | | `100` | Milliseconds between checks for results. The final wait is clamped to the remaining budget. |

### Named instances

Give a workload its own settings and resolve it by name:

```php
// config/queue-concurrency.php
'instances' => [
    'reports' => ['queue' => 'reports', 'timeout' => 120],
],
```

```php
Concurrency::driver('reports')->run([...]);
```

The `concurrency.drivers.<name>` shape proposed in the framework pull request is
read too, so a config written for the merged version keeps working:

```php
// config/concurrency.php
'drivers' => [
    'reports' => ['driver' => 'queue', 'queue' => 'reports', 'timeout' => 120],
],
```

## Things to know

- **The cache store must be shared between the caller and the workers.** That
  is how results get home. `array`, `null`, `session`, `octane` and `apc` are
  rejected outright for asynchronous connections, with an error saying so.
  Use `redis`, `memcached` or `database`.
- **The queue connection must actually be consumed.** `null`, `deferred` and
  `background` connections are rejected, because their jobs would never run
  while the caller waits.
- **Do not call `run()` from a worker consuming the same queue.** It can starve
  until the timeout. Give the tasks a dedicated queue, or spare capacity.
- **The wait is bounded, and that is the point.** Work the client should not
  wait for still belongs in an ordinary queued job.
- **Task closures are serialized.** Keep them small and avoid capturing large
  objects. Watch out in particular for defining them inside an arrow function,
  which captures its enclosing scope by value.
- **Failures are reported twice, deliberately.** The caller gets the original
  exception rethrown, and the worker still records a failed job, so nothing
  disappears from `failed_jobs` or Horizon.
- **The `sync` connection is supported and runs inline.** It is useful for
  tests and local work, but the tasks run one after another, so there is no
  parallelism to gain.

## Relationship to laravel/framework#61273

This package is the code from
[laravel/framework#61273](https://github.com/laravel/framework/pull/61273),
packaged so it can be used before that pull request is reviewed and merged. The
driver, the queued job, the result envelope and both exception classes are
carried over from the pull request unchanged apart from their namespace, so the
behaviour is the same one the pull request's test suite pins.

No framework patch is needed. `ConcurrencyManager` extends
`MultipleInstanceManager`, which already accepts custom driver creators, so the
service provider registers the driver through `extend()` on a stock Laravel.

The config keys, the environment variable names and the driver name are all
identical to the pull request, so migrating once it lands is a config move and
an import change:

```php
- use MathiasGrimm\QueueConcurrency\TaskTimedOutException;
+ use Illuminate\Concurrency\TaskTimedOutException;
```

The package steps aside on its own: if `Illuminate\Concurrency\QueueDriver`
ever exists, the service provider registers nothing and the first-party driver
wins. You can then remove the package at your leisure.

## Testing

```bash
composer test
composer analyse
composer lint
```

There is also a throwaway application under `workbench/` for driving the driver
by hand against genuinely separate worker processes, which no in-process test
can do. Build it once:

```bash
vendor/bin/testbench workbench:build
# SQLite serialises writers, so let the workers share the queue table.
php -r 'file_exists($f = "workbench/database/database.sqlite") && (new PDO("sqlite:$f"))->exec("PRAGMA journal_mode=WAL");'
```

`testbench serve` and `testbench queue:work` boot separate processes that read
their own `.env` rather than `testbench.yaml`, so pass the settings through the
environment. In one shell:

```bash
export DB_CONNECTION=sqlite QUEUE_CONNECTION=database CACHE_STORE=file
export DB_DATABASE="$PWD/workbench/database/database.sqlite"
vendor/bin/testbench serve
```

And a few workers in another, so there is something to parallelise across:

```bash
export DB_CONNECTION=sqlite QUEUE_CONNECTION=database CACHE_STORE=file
export DB_DATABASE="$PWD/workbench/database/database.sqlite"
for i in 1 2 3; do vendor/bin/testbench queue:work --queue=default --tries=1 & done
```

Then open `http://127.0.0.1:8000/` for the list of demo endpoints.
`/demo-benchmark` runs the same three two second tasks on all three drivers:

```
    sync: 6.01s   one pid, one task after another
 process: 2.17s   three local PHP processes
   queue: 2.54s   three queue worker pids
```

## Contributing

Pull requests are welcome. Please keep `composer test`, `composer analyse` and
`composer lint` green.

## Security

If you discover a security issue, please email mathiasgrimm@gmail.com rather
than using the issue tracker.

## Credits

- [Mathias Grimm](https://github.com/mathiasgrimm)
- [All Contributors](https://github.com/mathiasgrimm/laravel-queue-concurrency/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
