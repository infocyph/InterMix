<?php

namespace Infocyph\InterMix\Memoize;

use Countable;
use Infocyph\InterMix\Fence\Single;
use WeakMap;

final class WeakCache implements Countable
{
    use Single;

    private WeakMap $values;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * Constructs a new instance of the WeakCache class.
     *
     * This is a protected method to prevent external instantiation.
     *
     * @return void
     */
    protected function __construct()
    {
        $this->values = new WeakMap();
    }

    /**
     * Retrieves a memoized value of the provided callable.
     *
     * @param  object  $classObject  The class object for which the value is being retrieved.
     * @param  string  $signature  The unique signature of the value to retrieve.
     * @param  callable  $callable  The function to call if the value does not exist in the cache.
     * @param  array|string  $parameters  The parameters to pass to the callable function.
     * @return mixed The retrieved value from the cache or the generated value from the callable function.
     */
    public function get(object $classObject, string $signature, callable $callable, array|string $parameters = []): mixed
    {
        $this->values[$classObject] ??= [];

        if (isset($this->values[$classObject][$signature])) {
            $this->hits++;

            return $this->values[$classObject][$signature];
        }

        $this->misses++;
        $value = call_user_func_array($callable, (array) $parameters);
        $this->values[$classObject][$signature] = $value;

        return $value;
    }

    /**
     * Forget the cache values associated with the given class object.
     *
     * @param  object  $classObject  The class object for which the cache values should be forgotten.
     */
    public function forget(object $classObject): void
    {
        unset($this->values[$classObject]);
    }

    /**
     * Clear the cache.
     *
     * Resets the cache's internal state to empty, dropping all cached values and
     * resetting the hit/miss statistics.
     */
    public function flush(): void
    {
        $this->values = new WeakMap();
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Get the cache hit/miss statistics.
     *
     * @return array Associative array containing the following keys:
     *               - `hits`: The number of cache hits.
     *               - `misses`: The number of cache misses.
     *               - `total`: The total number of cache hits and misses.
     */
    public function getStatistics(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $this->hits + $this->misses,
        ];
    }

    /**
     * @return int The number of class objects currently memoized.
     */
    public function count(): int
    {
        return count($this->values);
    }
}
