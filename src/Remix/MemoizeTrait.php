<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Remix;

trait MemoizeTrait
{
    /** @var array<string,mixed> */
    private array $__memo = [];


    /**
     * Retrieves a memoized value of the provided callable.
     *
     * If the value is not cached, it calls the provided function and stores the result in the cache.
     * If the value is cached, it returns the cached value immediately.
     *
     * @param string $key The unique key of the value to retrieve and cache.
     * @param callable $producer The function to call if the value does not exist in the cache.
     * @return mixed The retrieved value from the cache or the generated value from the callable function.
     */
    protected function memoize(string $key, callable $producer): mixed
    {
        if (! array_key_exists($key, $this->__memo)) {
            $this->__memo[$key] = $producer();
        }
        return $this->__memo[$key];
    }

    /**
     * Clears the memoized value(s) stored in the cache.
     *
     * If no key is provided, all cached values will be cleared.
     * If a key is provided, only the corresponding cached value will be removed.
     *
     * @param string|null $key The key of the cached value to clear, or null to clear all cached values.
     * @return void
     */
    protected function memoizeClear(?string $key = null): void
    {
        if (is_null($key)) {
            $this->__memo = [];
        } elseif (isset($this->__memo[$key])) {
            unset($this->__memo[$key]);
        }
    }
}
