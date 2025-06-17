<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use BadMethodCallException;

/**
 * Tiny syntactic sugar layer for the Container *itself*.
 *
 * Gives you `$c('id')`, `$c->foo`, `$c['foo']`, and a couple of helpers
 * while staying completely optional – remove the trait and nothing breaks.
 */
trait ContainerProxy
{
    /* ($c)('service') */
    public function __invoke(string $id): mixed
    {
        return $this->get($id);
    }

    /* property access */
    public function __get(string $id): mixed
    {
        return $this->get($id);
    }
    public function __set(string $id, mixed $def): void
    {
        $this->definitions()->bind($id, $def);
    }
    public function __isset(string $id): bool
    {
        return $this->has($id);
    }

    /* [] sugar – enable by `implements ArrayAccess` on Container */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }
    public function offsetSet(mixed $offset, mixed $v): void
    {
        $this->definitions()->bind((string) $offset, $v);
    }
    public function offsetUnset(mixed $offset): void
    { /* silently ignore */
    }

    /* optional convenience forwarder */
    public function __call(string $method, array $args): mixed
    {
        if (!\method_exists($this, $method)) {
            throw new BadMethodCallException("Undefined method $method()");
        }
        return $this->$method(...$args);
    }
}
