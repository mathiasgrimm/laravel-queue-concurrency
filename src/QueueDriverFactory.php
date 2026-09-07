<?php

namespace MathiasGrimm\QueueConcurrency;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;

/**
 * Builds the queue concurrency driver for one instance name.
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
     * @param  array<string, mixed>  $resolved  The config the manager resolved for this instance.
     */
    public function __invoke(Application $app, array $resolved = []): QueueDriver
    {
        $config = $app->make(ConfigRepository::class);

        // A released manager only reads "concurrency.driver.<name>", and when
        // that key is missing it invents ['driver' => <name>]. Anything richer
        // than that placeholder is a real instance config the manager already
        // found, and it describes the instance being resolved rather than this
        // factory's own name, so the name keyed lookups are skipped.
        $placeholder = $resolved === [] || $resolved === ['driver' => $this->name];

        $options = array_merge(
            Arr::except((array) $config->get('queue-concurrency', []), ['instances']),
            $placeholder ? $this->instanceOptions($config) : [],
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
     * Get the configured option overrides for this instance name.
     *
     * @return array<string, mixed>
     */
    protected function instanceOptions(ConfigRepository $config): array
    {
        return array_merge(
            (array) $config->get('queue-concurrency.instances.'.$this->name, []),
            (array) $config->get('concurrency.driver.'.$this->name, []),
            (array) $config->get('concurrency.drivers.'.$this->name, []),
        );
    }
}
