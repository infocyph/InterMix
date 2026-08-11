<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use BadMethodCallException;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ServiceId;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;

/**
 * Provides a fluent proxy interface for container operations.
 *
 *  This trait enables a class to delegate method calls and property access to an underlying
 *  container instance while maintaining a fluent interface. It's designed to be used by manager
 *  classes that need to expose container functionality with a clean, object-oriented API.
 *
 * @property-read Container $container The underlying container instance
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
    /**
     * Delegates method calls to the underlying container.
     *
     * If the method is called on the container and returns the container instance,
     * returns $this instead to maintain fluent chaining on the manager.
     *
     * @param string $method The name of the method to call
     * @param array<int,mixed> $args The arguments to pass to the method
     * @return mixed The result of the method call, or $this for fluent chaining
     * @throws BadMethodCallException When the method does not exist on the container
     */
    public function __call(string $method, array $args): mixed
    {
        if (!is_callable([$this->container, $method])) {
            throw new BadMethodCallException("Container has no {$method}()");
        }

        $result = $this->container->$method(...$args);

        return $result === $this->container ? $this : $result;
    }

    /**
     * Retrieves a service or parameter from the container using property access.
     *
     * @param string $id The service or parameter ID
     * @return mixed The resolved service or parameter value
     * @throws InvalidArgumentException
     */
    public function __get(string $id): mixed
    {
        return $this->container->get($id);
    }

    /**
     * Retrieves a service or parameter from the container using function call syntax.
     *
     * @param string $id The service or parameter ID
     * @return mixed The resolved service or parameter value
     *
     * @throws InvalidArgumentException
     * @example $service = $manager('service_id');
     */
    public function __invoke(string $id): mixed
    {
        return $this->container->get($id);
    }

    /**
     * Checks if a service or parameter exists in the container.
     *
     * @param string $id The service or parameter ID to check
     * @return bool True if the container can resolve the ID, false otherwise
     */
    public function __isset(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * Binds a service or parameter to the container using property assignment.
     *
     * @param string $id The service or parameter ID
     * @param mixed $definition The service definition or parameter value
     * @throws ContainerException
     */
    public function __set(string $id, mixed $definition): void
    {
        $this->container->definitions()->bind($id, $definition);
    }

    /** Return to the owning Container. */
    public function end(): Container
    {
        return $this->container;
    }

    /**
     * Checks if an offset exists (ArrayAccess implementation).
     *
     * @param mixed $offset The offset to check
     * @return bool True if the offset exists, false otherwise
     */
    public function offsetExists(mixed $offset): bool
    {
        try {
            $id = ServiceId::from($offset);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->__isset($id);
    }

    /**
     * Retrieves an offset's value (ArrayAccess implementation).
     *
     * @param mixed $offset The offset to retrieve
     * @return mixed The value at the specified offset
     * @throws InvalidArgumentException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get(ServiceId::from($offset));
    }

    /**
     * Sets an offset's value (ArrayAccess implementation).
     *
     * @param mixed $offset The offset to set
     * @param mixed $value The value to set
     * @throws ContainerException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set(ServiceId::from($offset), $value);
    }

    /**
     * @param mixed $offset The offset to unset.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->container->unbind(ServiceId::from($offset));
    }
}
