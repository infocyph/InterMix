<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Container;
use Throwable;
use WeakMap;

/** @internal */
trait ResolvesMissingServices
{
    /** @var array<string, true> */
    private array $missingResolving = [];

    /** @var WeakMap<Throwable, true>|null */
    private ?WeakMap $onMissingFailures = null;

    /** @var list<callable(string, Container): void> */
    private array $onMissingHooks = [];

    /** @internal */
    public function isOnMissingFailure(Throwable $throwable): bool
    {
        return $this->onMissingFailures !== null
            && isset($this->onMissingFailures[$throwable]);
    }

    public function onMissing(callable $hook): void
    {
        $this->checkIfLocked();
        $this->onMissingHooks[] = $hook;
    }

    /**
     * Give host callbacks one guarded opportunity to register a missing ID.
     *
     * @internal
     * @phpstan-impure
     */
    public function tryResolveMissing(string $id): bool
    {
        if ($this->container->has($id)) {
            return true;
        }
        if (isset($this->missingResolving[$id])) {
            return false;
        }

        $this->missingResolving[$id] = true;

        try {
            foreach ($this->onMissingHooks as $hook) {
                try {
                    $hook($id, $this->container);
                } catch (Throwable $throwable) {
                    $this->onMissingFailures ??= new WeakMap();
                    $this->onMissingFailures[$throwable] = true;

                    throw $throwable;
                }

                if ($this->container->has($id)) {
                    return true;
                }
            }

            return false;
        } finally {
            unset($this->missingResolving[$id]);
        }
    }
}
