<?php

namespace MathiasGrimm\QueueConcurrency;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;

/**
 * Builds the queue concurrency driver for one registered instance name.
 *
 * This is a class rather than a closure in the service provider because
 * MultipleInstanceManager::extend() rebinds the callback it is handed to the
 * manager, so a closure calling $this would reach the manager instead.
 */
class QueueDriverFactory
{
    public function __construct(protected string $name)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $resolved  The config the manager resolved for the instance being built.
     */
    public function __invoke(Application $app, array $resolved = []): QueueDriver
    {
        $config = $app->make(ConfigRepository::class);

        // The manager routes by driver name, not instance name. When it finds
        // a legacy "concurrency.driver.<other>" entry whose driver is "queue"
        // it still calls the creator registered as "queue", handing over that
        // entry as $resolved. Anything richer than the bare placeholder the
        // manager invents for an unconfigured name is therefore a complete
        // instance config for some other instance, and this factory's own
        // name keyed options must stay out of it. The bare shape can only be
        // the placeholder because the service provider refuses a legacy entry
        // that declares no options, which is the one other way to produce it.
        $placeholder = $resolved === [] || $resolved === ['driver' => $this->name];

        $options = array_merge(
            static::defaults($config),
            $placeholder ? static::optionsFor($config, $this->name) : [],
            $resolved,
        );

        return new QueueDriver(
            $app->make(Dispatcher::class),
            $app->make(CacheFactory::class),
            $config,
            Arr::except($options, ['driver']),
        );
    }

    /**
     * The options every instance starts from.
     *
     * @return array<string, mixed>
     */
    public static function defaults(ConfigRepository $config): array
    {
        return Arr::except((array) $config->get('queue-concurrency', []), ['instances']);
    }

    /**
     * The options configured for a named instance, by instance name.
     *
     * Only the two sources the manager cannot deliver itself are read here.
     * A legacy "concurrency.driver.<name>" entry is already handed to the
     * creator as its resolved config, so reading it by name as well would
     * let one instance's options leak into another.
     *
     * @return array<string, mixed>
     */
    public static function optionsFor(ConfigRepository $config, string $name): array
    {
        return array_merge(
            (array) $config->get('queue-concurrency.instances.'.$name, []),
            (array) $config->get('concurrency.drivers.'.$name, []),
        );
    }
}
