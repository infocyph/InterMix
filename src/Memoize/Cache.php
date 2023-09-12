<?php

namespace AbmmHasan\InterMix\Memoize;

use AbmmHasan\InterMix\Fence\Single;
use Countable;

class Cache implements Countable
{
    use Single;

    private array $values = [];

    /**
     * Retrieves a value from the cache if it exists, otherwise calls a given function
     * to generate the value and stores it in the cache for future use.
     *
     * @param string $signature The unique signature of the value to retrieve.
     * @param callable $callable The function to call if the value does not exist in the cache.
     * @param array $parameters The parameters to pass to the callable function.
     * @return mixed The retrieved value from the cache or the generated value from the callable function.
     */
    public function get(string $signature, callable $callable, array $parameters): mixed
    {
        return $this->values[$signature] ??= call_user_func_array($callable, $parameters);
    }

    /**
     * Forget cache by key
     *
     * @param string $signature The unique signature of the value to forget.
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
