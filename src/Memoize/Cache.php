<?php

namespace AbmmHasan\InterMix\Memoize;


use AbmmHasan\InterMix\Fence\Single;
use Countable;

class Cache implements Countable
{
    use Single;

    private array $values = [];

    /**
     * Get cached data / if not cached, execute and cache
     *
     * @param string $signature
     * @param callable $callable
     * @param array $parameters
     * @return mixed
     */
    public function get(string $signature, callable $callable, array $parameters): mixed
    {
        return $this->values[$signature] ??= call_user_func_array($callable, $parameters);
    }

    /**
     * Forget cache by key
     *
     * @param string $signature
     * @return void
     */
    public function forget(string $signature): void
    {
        unset($this->values[$signature]);
    }

    /**
     * Flush the cache
     *
     * @return void
     */
    public function flush(): void
    {
        $this->values = [];
    }

    /**
     * Get count
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->values);
    }
}
