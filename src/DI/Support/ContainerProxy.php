<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Internal\ServiceId;
use Infocyph\InterMix\DI\Resolver\ConcurrentRepository;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;

/**
 * Small convenience/runtime surface mixed into the Container itself.
 *
 * Keeps syntax helpers and framework-neutral scope-context plumbing out of the
 * main Container implementation while delegating all state ownership to the
 * repository.
 */
trait ContainerProxy
{
    /**
     * Magic getter method.
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
     * @throws ContainerException
     */
    public function __set(string $id, mixed $def): void
    {
        $this->definitions()->bind($id, $def);
    }

    public function captureScopeContext(): ScopeContext
    {
        return $this->scopeContextRepository()->captureScopeContext();
    }

    /**
     * ArrayAccess offsetExists implementation.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($this->offsetToString($offset));
    }

    /**
     * Gets the value for the specified offset from the container.
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
     * @suppress PhanUnreferencedPublicMethod
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unbind(ServiceId::from($offset));
    }

    /**
     * Reset only the current execution carrier's DI scope state.
     *
     * This is safe for framework/runtime finally blocks: owned nested scopes are
     * unwound with normal leave hooks, while an attached shared scope is merely
     * detached after its carrier-local child frames are closed.
     */
    public function resetCurrentExecutionScope(): void
    {
        $this->scopeContextRepository()->resetCurrentExecutionScope();
    }

    public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed
    {
        $repository = $this->scopeContextRepository();
        $repository->attachScopeContext($scopeContext);

        try {
            return $callback($this);
        } finally {
            $repository->detachScopeContextIfAttached($scopeContext);
        }
    }

    private function offsetToString(mixed $offset): string
    {
        return ServiceId::from($offset);
    }

    private function scopeContextRepository(): ConcurrentRepository
    {
        $repository = $this->getRepository();
        if (!$repository instanceof ConcurrentRepository) {
            throw new ContainerException('Scope-context propagation requires a concurrent InterMix repository.');
        }

        return $repository;
    }
}
