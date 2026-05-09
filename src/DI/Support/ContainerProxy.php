<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use BadMethodCallException;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use Stringable;

/**
 * Tiny syntactic sugar layer for the Container *itself*.
 *
 * Gives you `$c('id')`, `$c->foo`, `$c['foo']`, and a couple of helpers
 * while staying completely optional – remove the trait and nothing breaks.
 */
trait ContainerProxy
{
    /**
     * Delegate calls to methods on the container.
     *
     * @param string $method The name of the method to call.
     * @param array<int, mixed> $args The arguments to pass to the method.
     *
     * @return mixed The result of the method call.
     *
     * @throws BadMethodCallException If the method does not exist.
     */
    public function __call(string $method, array $args): mixed
    {
        if (!\method_exists($this, $method)) {
            throw new BadMethodCallException("Undefined method $method()");
        }

        return $this->$method(...$args);
    }

    /**
     * Magic getter method.
     *
     *
     * @throws InvalidArgumentException
     */
    public function __get(string $id): mixed
    {
        return $this->get($id);
    }

    /**
     * Allows for a quick shorthand: `$container('id')`
     *
     *
     * @throws InvalidArgumentException
     */
    public function __invoke(string $id): mixed
    {
        return $this->get($id);
    }

    /**
     * Magic isset() method.
     */
    public function __isset(string $id): bool
    {
        return $this->has($id);
    }

    /**
     * Magic setter method.
     *
     *
     * @throws ContainerException
     */
    public function __set(string $id, mixed $def): void
    {
        $this->definitions()->bind($id, $def);
    }

    /**
     * @inheritDoc
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($this->offsetToString($offset));
    }

    /**
     * Gets the value for the specified offset from the container.
     *
     * This method allows the use of array-like syntax to retrieve a value
     * from the container. The offset is converted to a string before
     * retrieval.
     *
     * @param mixed $offset The key at which to retrieve the value.
     *
     * @return mixed The value at the specified offset.
     * @throws InvalidArgumentException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($this->offsetToString($offset));
    }

    /**
     * Sets a value in the container's definitions at the specified offset.
     *
     * This method allows the use of array-like syntax to bind a definition
     * to the container. The offset is converted to a string before binding.
     *
     * @param mixed $offset The key at which to set the value.
     * @param mixed $v The value to bind to the offset.
     *
     * @throws ContainerException
     */
    public function offsetSet(mixed $offset, mixed $v): void
    {
        $this->definitions()->bind($this->offsetToString($offset), $v);
    }

    /**
     * ArrayAccess offsetUnset implementation.
     *
     * @param mixed $offset The key to unset.
     *
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function offsetUnset(mixed $offset): void
    { /* silently ignore */
    }

    private function offsetToString(mixed $offset): string
    {
        if (is_string($offset)) {
            return $offset;
        }
        if ($offset instanceof Stringable) {
            return (string) $offset;
        }
        if (is_int($offset) || is_float($offset) || is_bool($offset) || $offset === null) {
            return (string) $offset;
        }

        throw new BadMethodCallException('Array-access offset must be string-convertible.');
    }
}
