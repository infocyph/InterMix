<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use BadMethodCallException;
use Infocyph\InterMix\DI\Container;

/**
 * Tiny façade that lets a **manager** delegate to its underlying container.
 *
 * Every class that ➊ declares `protected Container $container`
 * and ➋ `use`s this trait instantly gets:
 *
 *  •  `$mgr('id')`                      –– same as `$mgr->get('id')`
 *  •  `$mgr->serviceId`                –– property read sugar
 *  •  `$mgr->serviceId = fn() => …`     –– singleton bind
 *  •  `$mgr['id']` (if you `implements ArrayAccess`)
 *  •  `__call()` pass-through with fluent-chain preservation
 */
trait ManagerProxy
{
    /* magic method forwarding – keeps fluent chain on manager */
    public function __call(string $method, array $args): mixed
    {
        if (!\method_exists($this->container, $method)) {
            throw new BadMethodCallException("Container has no {$method}()");
        }

        $result = $this->container->$method(...$args);

        return $result === $this->container ? $this : $result;
    }

    /* magic property sugar */
    public function __get(string $id): mixed
    {
        return $this->container->get($id);
    }

    /* quick “()” shorthand */
    public function __invoke(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function __isset(string $id): bool
    {
        return $this->container->has($id);
    }

    public function __set(string $id, mixed $definition): void
    {
        $this->container->definitions()->bind($id, $definition);
    }
    /**
     * Ends the current scope and returns the Container instance.
     *
     * When called, this method will return the Container instance and
     * remove the current scope from the stack, effectively ending the
     * current scope.
     *
     * @return Container The Container instance.
     */
    public function end(): Container
    {
        return $this->container;
    }

    /* optional ArrayAccess implementation */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $v): void
    {
        $this->__set((string)$offset, $v);
    }

    public function offsetUnset(mixed $offset): void
    { /* deliberately a no-op */
    }
}
