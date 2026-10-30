<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemPoolInterface;

/** @internal */
trait ManagesDefinitionCache
{
    private ?CacheItemPoolInterface $definitionCache = null;

    /** @var array<string, true> */
    private array $definitionCacheEligibleIds = [];

    private bool $definitionCacheExplicitOnly = false;

    private bool $definitionCacheFailOpen = true;

    private ?string $definitionCacheGeneration = null;

    private ?string $definitionCacheNamespace = null;

    private ?string $definitionCachePrefix = null;

    private int $definitionCacheRevision = 0;

    /**
     * Provide the container namespace used to isolate persistent cache keys.
     */
    abstract public function getAlias(): string;

    /**
     * Provide the selected environment used to isolate persistent cache keys.
     */
    abstract public function getEnvironment(): ?string;

    /**
     * Notify the configuration owner when persistent cache settings change.
     *
     * @internal
     */
    abstract public function notifyConfigurationMutation(): void;

    /**
     * Reject persistent cache configuration changes after runtime configuration is locked.
     */
    abstract protected function checkIfLocked(): void;

    /**
     * Expose the configured PSR-6 pool, or null when persistent caching is disabled.
     */
    public function getDefinitionCache(): ?CacheItemPoolInterface
    {
        return $this->definitionCache;
    }

    /**
     * Check whether an entry was explicitly opted into persistent value caching.
     */
    public function isDefinitionCacheEligible(string $id): bool
    {
        return isset($this->definitionCacheEligibleIds[$id]);
    }

    /**
     * Check whether cache failures may fall back to normal definition resolution.
     */
    public function isDefinitionCacheFailOpen(): bool
    {
        return $this->definitionCacheFailOpen;
    }

    /**
     * Build deterministic PSR-6 keys: explicit identities use namespace/generation/environment; legacy identities also use local revision.
     */
    public function makeDefinitionCacheKey(string $definition): string
    {
        if ($this->definitionCacheNamespace !== null) {
            $this->definitionCachePrefix ??= 'imx11.'
                . substr(hash('xxh128', $this->definitionCacheNamespace), 0, 16)
                . '.' . substr(
                    hash('xxh128', $this->definitionCacheGeneration ?? ''),
                    0,
                    16,
                )
                . '.';

            return $this->definitionCachePrefix
                . substr(hash('xxh128', serialize([$definition, $this->getEnvironment()])), 0, 16);
        }

        $this->definitionCachePrefix ??= 'imx.'
            . substr(hash('xxh128', $this->getAlias()), 0, 16)
            . '.' . substr(
                hash(
                    'xxh128',
                    serialize([$this->definitionCacheGeneration, $this->definitionCacheRevision]),
                ),
                0,
                16,
            )
            . '.';

        return $this->definitionCachePrefix
            . substr(hash('xxh128', serialize([$definition, $this->getEnvironment()])), 0, 16);
    }

    /**
     * Invalidate legacy cache identities after a local revision. Explicit keys remain stable per deployment generation.
     */
    public function rotateDefinitionCacheGeneration(): void
    {
        ++$this->definitionCacheRevision;
        $this->definitionCachePrefix = null;
    }

    /**
     * Configure the pool and cache isolation policy, rejecting empty explicit identities.
     */
    public function setDefinitionCache(
        CacheItemPoolInterface $cache,
        ?string $generation = null,
        bool $failOpen = true,
        ?string $namespace = null,
        bool $explicitOnly = false,
    ): void {
        $this->checkIfLocked();
        if ($generation === '') {
            throw new ContainerException('Definition cache generation cannot be empty.');
        }
        if ($namespace === '') {
            throw new ContainerException('Definition cache namespace cannot be empty.');
        }

        if ($this->definitionCache === $cache
            && ($generation === null || $generation === $this->definitionCacheGeneration)
            && $this->definitionCacheFailOpen === $failOpen
            && $this->definitionCacheNamespace === $namespace
            && $this->definitionCacheExplicitOnly === $explicitOnly
        ) {
            return;
        }

        $this->notifyConfigurationMutation();
        $this->definitionCache = $cache;
        $this->definitionCacheExplicitOnly = $explicitOnly;
        $this->definitionCacheFailOpen = $failOpen;
        $this->definitionCacheNamespace = $namespace;

        if ($generation !== null && $generation !== $this->definitionCacheGeneration) {
            $this->definitionCacheGeneration = $generation;
            $this->definitionCacheRevision = 0;
        }

        $this->definitionCachePrefix = null;
    }

    /**
     * Opt an entry into or out of explicit-only persistent caching.
     */
    public function setDefinitionCacheEligible(string $id, bool $eligible = true): void
    {
        $this->checkIfLocked();
        if ($eligible) {
            $this->definitionCacheEligibleIds[$id] = true;

            return;
        }

        unset($this->definitionCacheEligibleIds[$id]);
    }

    /**
     * Reject objects and unbounded structures from persistent definition value storage.
     */
    public function shouldPersistDefinitionValue(mixed $value): bool
    {
        return BoundedValueInspector::isScalarNullArray($value);
    }

    /**
     * Require a configured pool and, in explicit-only mode, the entry's eligibility.
     */
    public function usesDefinitionCacheFor(string $id): bool
    {
        return $this->definitionCache !== null
            && (!$this->definitionCacheExplicitOnly || isset($this->definitionCacheEligibleIds[$id]));
    }

    /**
     * @return array{lifetime: LifetimeEnum, alias: AliasDefinition|null}
     */
    private function definitionLifetimeFor(string $id): array
    {
        $cached = $this->definitionLifetimeCache[$id] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $resolved = $this->resolveDefinitionLifetime($id);
        if ($this->hasFunctionReference($id)) {
            $this->definitionLifetimeCache[$id] = $resolved;
        }

        return $resolved;
    }
}
