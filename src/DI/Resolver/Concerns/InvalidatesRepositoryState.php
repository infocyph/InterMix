<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

/** @internal */
trait InvalidatesRepositoryState
{
    private bool $hasPropertyResources = false;

    /**
     * Apply repository lock and concurrency checks before changing configuration.
     */
    public function assertMutable(): void
    {
        $this->checkIfLocked();
    }

    /**
     * Check whether explicit property resources require runtime property processing.
     *
     * @internal
     */
    public function hasPropertyResources(): bool
    {
        return $this->hasPropertyResources;
    }

    /**
     * Clear class-derived resolution state after its resource configuration changes.
     */
    public function invalidateClass(string $class): void
    {
        $this->notifyConfigurationMutation();
        if (!$this->hasPropertyResources) {
            $properties = $this->classResource[$class]['property'] ?? null;
            $this->hasPropertyResources = is_array($properties) && $properties !== [];
        }

        unset($this->resolvedResource[$class], $this->resolved[$class], $this->resolvedSingleton[$class]);
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$class]);
        }
        $this->invalidateCompiledResolvers();
        $this->rotateDefinitionCacheGeneration();
    }

    /**
     * Discard the legacy compiled resolver after a configuration change.
     */
    public function invalidateCompiledResolvers(): void
    {
        $this->compiledResolver = null;
        $this->compiledResolverIds = [];
    }

    /**
     * Clear definition-derived values, lifetime metadata and compiled resolution state.
     */
    public function invalidateDefinition(string $id): void
    {
        $this->notifyConfigurationMutation();
        $this->definitionLifetimeCache = [];
        unset($this->resolved[$id], $this->resolvedSingleton[$id], $this->scopeSeedAllowedIds[$id]);
        foreach (array_keys($this->resolvedDefinition) as $key) {
            if ($key === $id || str_starts_with($key, $id . '@env:')) {
                unset($this->resolvedDefinition[$key]);
            }
        }
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$id]);
            if ($this->resolvedScoped[$scope] === []) {
                unset($this->resolvedScoped[$scope]);
            }
        }
        unset($this->resolvedResource[$id]);
        $this->invalidateCompiledResolvers();
        $this->rotateDefinitionCacheGeneration();
    }

    /**
     * Clear all resolution-derived caches and rotate persistent cache identity.
     */
    public function invalidateResolutionConfiguration(): void
    {
        $this->notifyConfigurationMutation();
        $this->resolved = [];
        $this->resolvedSingleton = [];
        $this->resolvedDefinition = [];
        $this->resolvedResource = [];
        $this->resolvedScoped = [];
        $this->definitionLifetimeCache = [];
        $this->scopeSeedAllowedIds = [];
        $this->invalidateCompiledResolvers();
        $this->rotateDefinitionCacheGeneration();
    }
}
