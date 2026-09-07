<?php

namespace MathiasGrimm\QueueConcurrency;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QueueConcurrencyServiceProvider extends ServiceProvider
{
    /**
     * The concurrency driver name this package registers instances for.
     */
    public const DRIVER = 'queue';

    /**
     * The framework class that supersedes this package once it ships.
     */
    public const FRAMEWORK_DRIVER = 'Illuminate\Concurrency\QueueDriver';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-concurrency.php', 'queue-concurrency');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/queue-concurrency.php' => config_path('queue-concurrency.php'),
            ], 'queue-concurrency-config');
        }

        if (static::supersededByFramework()) {
            return;
        }

        // callAfterResolving covers both boot orders. It registers for a future
        // resolution, which keeps the framework's deferred provider deferred,
        // and if another provider already resolved the manager singleton it
        // runs right away against that instance instead of never.
        $this->callAfterResolving(ConcurrencyManager::class, function (ConcurrencyManager $manager) {
            $this->guardAgainstAmbiguousInstances($manager);

            foreach ($this->instanceNames() as $name) {
                $factory = new QueueDriverFactory($name);

                // extend() rebinds this callback to the manager, so it must
                // reach the factory through a captured variable rather than
                // through $this.
                $manager->extend($name, fn ($app, $config = []) => $factory($app, $config));
            }
        });
    }

    /**
     * Determine whether the framework ships its own queue concurrency driver.
     *
     * Custom creators are consulted before the manager's own create*Driver
     * methods, so without stepping aside this package would keep shadowing
     * the first-party driver after laravel/framework#61273 lands.
     */
    public static function supersededByFramework(): bool
    {
        return class_exists(static::FRAMEWORK_DRIVER);
    }

    /**
     * Get every instance name that needs a creator of its own.
     *
     * A legacy "concurrency.driver.<name>" entry is deliberately absent: the
     * manager routes it to the creator registered under its driver name and
     * hands over the entry as the resolved config, so it needs no creator.
     *
     * @return array<int, string>
     */
    protected function instanceNames(): array
    {
        $config = $this->app->make(ConfigRepository::class);

        $names = [static::DRIVER];

        // Named instances in the shape the framework pull request proposes. A
        // released manager never reads "concurrency.drivers", so each name is
        // registered as its own creator and the factory reads the options.
        foreach ((array) $config->get('concurrency.drivers', []) as $name => $instance) {
            if (is_array($instance) && ($instance['driver'] ?? null) === static::DRIVER) {
                $names[] = (string) $name;
            }
        }

        foreach ((array) $config->get('queue-concurrency.instances', []) as $name => $instance) {
            $names[] = (string) $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Refuse configurations the manager's routing cannot honour, loudly, rather
     * than letting an instance silently resolve with another instance's options.
     *
     * @throws InvalidArgumentException
     */
    protected function guardAgainstAmbiguousInstances(ConcurrencyManager $manager): void
    {
        $config = $this->app->make(ConfigRepository::class);

        // Custom creators win over the manager's own create*Driver methods, so
        // an instance called "process" would silently turn the framework's
        // default driver, and every plain Concurrency::run(), queue backed.
        foreach ($this->instanceNames() as $name) {
            if ($name !== static::DRIVER && method_exists($manager, 'create'.Str::studly($name).'Driver')) {
                throw new InvalidArgumentException(
                    "The concurrency instance name [{$name}] is reserved by the concurrency manager's own [{$name}] driver. Choose another name for the queue instance."
                );
            }
        }

        // A legacy entry is routed to the "queue" creator with only that entry
        // as its config, so options for the same name kept anywhere else can
        // never reach it. Refuse the split instead of dropping them.
        foreach ((array) $config->get('concurrency.driver', []) as $name => $instance) {
            if (! is_array($instance) || ($instance['driver'] ?? null) !== static::DRIVER) {
                continue;
            }

            if (QueueDriverFactory::optionsFor($config, (string) $name) !== []) {
                throw new InvalidArgumentException(
                    "The concurrency instance [{$name}] is defined under [concurrency.driver.{$name}] and also has options under [queue-concurrency.instances.{$name}] or [concurrency.drivers.{$name}]. The concurrency manager only hands the driver the [concurrency.driver.{$name}] entry, so keep every option for that instance there, or remove that entry and define the instance in one place."
                );
            }
        }
    }
}
