<?php

namespace Infocyph\InterMix\Memoize;

use Infocyph\InterMix\Fence\Single;
use Countable;
use WeakMap;

final class WeakCache implements Countable
{
    use Single;

    private WeakMap $values;

    /**
     * Constructor for the class.
     *
     * This function initializes the values property with a new WeakMap object.
     */
    protected function __construct()
    {
        $this->values = new WeakMap();
    }

    /**
     * Retrieves a value from the specified class object and signature,
     * or calls the provided callable with the given parameters and stores
     * the result if it has not been previously stored.
     *
     * @param object $classObject The class object from which to retrieve the value.
     * @param string $signature The signature of the value to retrieve.
     * @param callable $callable The callable to call if the value has not been previously stored.
     * @param mixed $parameters The parameters to pass to the callable.
     * @return mixed The retrieved value or the result of the callable.
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
