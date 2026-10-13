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

    /**
     * Check whether legacy misses can be handled by registered callbacks.
     *
     * @internal
     */
    public function hasMissingHooks(): bool
    {
        return $this->onMissingHooks !== [];
    }

    /**
     * Identify a missing-hook failure whose original exception must be preserved.
     *
     * @internal
     */
    public function isOnMissingFailure(Throwable $throwable): bool
    {
        return $this->onMissingFailures !== null
            && isset($this->onMissingFailures[$throwable]);
    }

    /**
     * Register legacy missing-entry callbacks while enforcing mutable configuration.
     */
    public function onMissing(callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->onMissingHooks[] = $hook;
    }

    /**
     * Run miss handlers with recursion protection and verify that resolution became possible.
     *
     * @internal
     * @phpstan-impure
     */
    public function tryResolveMissing(string $id): bool
    {
        // The overwhelmingly common case has no host activation hooks. Avoid a
        // second broad Container::has() walk in that path.
        if ($this->onMissingHooks === []) {
            return false;
        }
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
