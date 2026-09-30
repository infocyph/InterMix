<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Resolver\ConcurrentRepository;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;

/**
 * Runtime-only container conveniences with no configuration mutation surface.
 *
 * @internal
 */
trait RuntimeContainerProxy
{
    /** @throws InvalidArgumentException */
    public function __get(string $id): mixed
    {
        return $this->get($id);
    }

    /** @throws InvalidArgumentException */
    public function __invoke(string $id): mixed
    {
        return $this->get($id);
    }

    public function __isset(string $id): bool
    {
        return $this->has($id);
    }

    public function captureScopeContext(): ScopeContext
    {
        return $this->scopeContextRepository()->captureScopeContext();
    }

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

    private function scopeContextRepository(): ConcurrentRepository
    {
        if (!$this->repository instanceof ConcurrentRepository) {
            throw new ContainerException(
                'Scope-context propagation requires a concurrent InterMix repository.',
            );
        }

        return $this->repository;
    }
}
