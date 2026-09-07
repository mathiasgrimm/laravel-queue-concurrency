<?php

namespace MathiasGrimm\QueueConcurrency;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

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

        // Registering through afterResolving instead of the Concurrency facade
        // keeps the framework's deferred ConcurrencyServiceProvider deferred:
        // nothing resolves the manager until something asks for a driver.
        $this->app->afterResolving(ConcurrencyManager::class, function ($manager) {
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
     * Get every instance name that should resolve to a queue concurrency driver.
     *
     * @return array<int, string>
     */
    protected function instanceNames(): array
    {
        $config = $this->app->make(ConfigRepository::class);

        $names = [static::DRIVER];

        // A named instance declared the way the framework pull request shapes
        // it. A released manager never reads "concurrency.drivers", so the name
        // is registered as its own creator and the options are read by the
        // factory rather than handed over by the manager.
        foreach (['concurrency.drivers', 'concurrency.driver'] as $key) {
            foreach ((array) $config->get($key, []) as $name => $instance) {
                if (is_array($instance) && ($instance['driver'] ?? null) === static::DRIVER) {
                    $names[] = (string) $name;
                }
            }
        }

        foreach ((array) $config->get('queue-concurrency.instances', []) as $name => $instance) {
            $names[] = (string) $name;
        }

        return array_values(array_unique($names));
    }
}
