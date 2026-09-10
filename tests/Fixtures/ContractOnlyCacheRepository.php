<?php

namespace MathiasGrimm\QueueConcurrency\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * A repository that implements exactly the cache contract by delegation and
 * nothing else: no many(), no __call(). A tracing or metrics decorator is the
 * realistic shape of this, and it is legal for Cache::store() to return one.
 *
 * The PSR-16 methods take untyped parameters and typed returns, the way
 * Illuminate\Cache\Repository does, so the class loads against psr/simple-cache
 * 1, 2 and 3 alike; typed parameters are a fatal against the untyped 1.x
 * interface, which is what laravel/framework's prefer-lowest CI resolves.
 */
class ContractOnlyCacheRepository implements Repository
{
    public function __construct(protected Repository $inner, protected bool $generators = false)
    {
        //
    }

    public function pull($key, $default = null)
    {
        return $this->inner->pull($key, $default);
    }

    public function put($key, $value, $ttl = null)
    {
        return $this->inner->put($key, $value, $ttl);
    }

    public function add($key, $value, $ttl = null)
    {
        return $this->inner->add($key, $value, $ttl);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function remember($key, $ttl, Closure $callback)
    {
        return $this->inner->remember($key, $ttl, $callback);
    }

    public function sear($key, Closure $callback)
    {
        return $this->inner->sear($key, $callback);
    }

    public function rememberForever($key, Closure $callback)
    {
        return $this->inner->rememberForever($key, $callback);
    }

    public function touch($key, $ttl)
    {
        return $this->inner->touch($key, $ttl);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function getStore()
    {
        return $this->inner->getStore();
    }

    public function get($key, $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set($key, $value, $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete($key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $values = $this->inner->getMultiple($keys, $default);

        // PSR-16 only promises an iterable, so a strict implementation may
        // hand back a generator.
        return $this->generators ? (function () use ($values) {
            yield from $values;
        })() : $values;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple($keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has($key): bool
    {
        return $this->inner->has($key);
    }
}
