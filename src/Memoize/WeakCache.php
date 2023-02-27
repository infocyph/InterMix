<?php

namespace AbmmHasan\InterMix\Memoize;


use AbmmHasan\InterMix\Fence\Single;
use Countable;
use WeakMap;

final class WeakCache implements Countable
{
    use Single;

    private WeakMap $values;

    protected function __construct()
    {
        $this->values = new WeakMap();
    }

    /**
     * Get cached data / if not cached, execute and cache
     *
     * @param object $classObject
     * @param string $signature
     * @param callable $callable callable
     * @param mixed $parameters
     * @return mixed
     */
    public function get(object $classObject, string $signature, callable $callable, mixed $parameters): mixed
    {
        return $this->values[$classObject][$signature] ??= call_user_func_array($callable, (array)$parameters);
    }

    /**
     * Forget cache for a class
     *
     * @param object $classObject $this
     * @return void
     */
    public function forget(object $classObject): void
    {
        unset($this->values[$classObject]);
    }

    /**
     * Flush the cache
     *
     * @return void
     */
    public function flush(): void
    {
        $this->values = new WeakMap();
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
