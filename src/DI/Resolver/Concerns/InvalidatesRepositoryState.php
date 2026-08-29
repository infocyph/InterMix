<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

/** @internal */
trait InvalidatesRepositoryState
{
    private bool $hasPropertyResources = false;

    public function assertMutable(): void
    {
        $this->checkIfLocked();
    }

    /** @internal */
    public function hasPropertyResources(): bool
    {
        return $this->hasPropertyResources;
    }

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

    public function invalidateCompiledResolvers(): void
    {
        $this->compiledResolver = null;
        $this->compiledResolverIds = [];
    }

    public function invalidateDefinition(string $id): void
    {
        $this->notifyConfigurationMutation();
        unset($this->resolved[$id], $this->resolvedSingleton[$id]);
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

    public function invalidateResolutionConfiguration(): void
    {
        $this->notifyConfigurationMutation();
        $this->resolved = [];
        $this->resolvedSingleton = [];
        $this->resolvedDefinition = [];
        $this->resolvedResource = [];
        $this->resolvedScoped = [];
        $this->invalidateCompiledResolvers();
        $this->rotateDefinitionCacheGeneration();
    }
}
