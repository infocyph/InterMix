<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Remix;

/**
 * Proxy class for method chaining with the tap pattern.
 *
 * This class implements the "tap" pattern, allowing method calls to be
 * made on a target object while always returning the original target.
 * This enables fluent method chaining where intermediate operations
 * can be performed without breaking the chain.
 *
 * Commonly used in the tap() method of conditionable objects.
 */
class TapProxy
{
    /**
     * Create a new TapProxy instance.
     *
     * @param mixed $target The target object to proxy method calls to.
     */
    public function __construct(public mixed $target)
    {
    }

    /**
     * Proxy a method call to the target, then return the target.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $this->target->{$method}(...$parameters);
        return $this->target;
    }
}
