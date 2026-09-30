<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
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

    abstract public function getAlias(): string;

    abstract public function getEnvironment(): ?string;

    /** @internal */
    abstract public function notifyConfigurationMutation(): void;

    abstract protected function checkIfLocked(): void;

    public function getDefinitionCache(): ?CacheItemPoolInterface
    {
        return $this->definitionCache;
    }

    public function isDefinitionCacheEligible(string $id): bool
    {
        return isset($this->definitionCacheEligibleIds[$id]);
    }

    public function isDefinitionCacheFailOpen(): bool
    {
        return $this->definitionCacheFailOpen;
    }

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

            return $this->definitionCachePrefix . substr(hash('xxh128', $definition), 0, 16);
        }

        $this->definitionCachePrefix ??= 'imx.'
            . substr(hash('xxh128', $this->getAlias()), 0, 16)
            . '.' . substr(
                hash(
                    'xxh128',
                    ($this->definitionCacheGeneration ?? 'default') . "\0" . $this->definitionCacheRevision,
                ),
                0,
                16,
            )
            . '.';

        return $this->definitionCachePrefix
            . substr(hash('xxh128', $definition . "\0" . ($this->getEnvironment() ?? 'default')), 0, 16);
    }

    public function rotateDefinitionCacheGeneration(): void
    {
        ++$this->definitionCacheRevision;
        $this->definitionCachePrefix = null;
    }

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

    public function setDefinitionCacheEligible(string $id, bool $eligible = true): void
    {
        $this->checkIfLocked();
        if ($eligible) {
            $this->definitionCacheEligibleIds[$id] = true;

            return;
        }

        unset($this->definitionCacheEligibleIds[$id]);
    }

    public function shouldPersistDefinitionValue(mixed $value): bool
    {
        return BoundedValueInspector::isScalarNullArray($value);
    }

    public function usesDefinitionCacheFor(string $id): bool
    {
        return $this->definitionCache !== null
            && (!$this->definitionCacheExplicitOnly || isset($this->definitionCacheEligibleIds[$id]));
    }
}
