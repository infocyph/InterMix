<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Resolver\Concerns\InvalidatesRepositoryState;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesMissingServices;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;

/**
 * Central storage for the container's definitions, resolved instances, and
 * resolution configuration.
 */
class Repository
{
    use InvalidatesRepositoryState;
    use ResolvesMissingServices;

    private ?AttributeRegistry $attributeRegistry = null;

    /** @var array<string, array<string, mixed>> */
    private array $classResource = [];

    /** @var array<string, array{on: callable, params: array<int|string, mixed>}> */
    private array $closureResource = [];

    /** @var (Closure(Container, string): mixed)|null */
    private ?Closure $compiledResolver = null;

    /** @var array<string, mixed> */
    private array $compiledResolverIds = [];

    /** @var array<string, array<string, string>> */
    private array $conditionalBindings = [];

    /** @var (Closure(): void)|null */
    private ?Closure $configurationMutationListener = null;

    /** @var array<string, array<string, mixed>> */
    private array $contextualBindings = [];

    private string $currentScope = 'root';

    private ?string $defaultMethod = null;

    private ?CacheItemPoolInterface $definitionCache = null;

    private bool $definitionCacheFailOpen = true;

    private ?string $definitionCacheGeneration = null;

    private ?string $definitionCachePrefix = null;

    private int $definitionCacheRevision = 0;

    /** @var array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}> */
    private array $definitionMeta = [];

    /** @var array<string, array<string, array{lifetime?: LifetimeEnum, tags?: array<int, string>}>> */
    private array $definitionMetaByEnv = [];

    private bool $enableMethodAttribute = false;

    private bool $enablePropertyAttribute = false;

    private ?string $environment = null;

    /** @var array<string, mixed> */
    private array $functionReference = [];

    private bool $hasHooks = false;

    private bool $isLocked = false;

    private bool $lazyLoading = true;

    /** @var array<string, array<int, callable(string,mixed): void>> */
    private array $onResolvedHooks = [];

    /** @var array<string, array<int, callable(string): void>> */
    private array $onResolvingHooks = [];

    /** @var array<string, array<int, callable(string,Container): void>> */
    private array $onScopeLeaveHooks = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, mixed> */
    private array $resolvedDefinition = [];

    /** @var array<string, true> */
    private array $resolvedIds = [];

    /** @var array<string, ClassResolution> */
    private array $resolvedResource = [];

    /** @var array<string, array<string, mixed>> */
    private array $resolvedScoped = [];

    /** @var array<string, mixed> */
    private array $resolvedSingleton = [];

    /** @var array<string, array<string, mixed>> */
    private array $scopeSeeds = [];

    /** @var array<int, string> */
    private array $scopeStack = [];

    /** @var array<string, array<string, bool>> */
    private array $tagIndex = [];

    /** @var array<string, array<string, array<string, bool>>> */
    private array $tagIndexByEnv = [];

    /** @var array<string, array<string, bool>> */
    private array $tagOverrideIdsByEnv = [];

    private ?DebugTracer $tracer = null;

    private bool $tracingEnabled = false;

    /**
     * Bootstrap immutable repository facts directly. A fresh repository has no
     * runtime state to invalidate, so construction avoids the mutation path.
     */
    public function __construct(
        private readonly Container $container,
        private string $alias = 'default',
    ) {
        $this->functionReference[ContainerInterface::class] = $container;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addClassResource(string $class, string $key, array $data): void
    {
        $this->checkIfLocked();
        if (($this->classResource[$class][$key] ?? null) === $data) {
            return;
        }

        $this->classResource[$class][$key] = $data;
        $this->invalidateClass($class);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function addClosureResource(string $alias, callable $function, array $params = []): void
    {
        $this->checkIfLocked();
        $this->closureResource[$alias] = ['on' => $function, 'params' => $params];
        $this->invalidateDefinition($alias);
    }

    public function attributeRegistry(): AttributeRegistry
    {
        return $this->attributeRegistry ??= new AttributeRegistry($this->container);
    }

    public function bindInterfaceForEnv(string $env, string $interface, string $concrete): void
    {
        $this->checkIfLocked();
        if (($this->conditionalBindings[$env][$interface] ?? null) === $concrete) {
            return;
        }

        $this->conditionalBindings[$env][$interface] = $concrete;
        $this->invalidateResolutionConfiguration();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function dispatchResolvedHooks(string $id, mixed $value): void
    {
        if (!$this->hasHooks) {
            return;
        }

        foreach ($this->onResolvedHooks[$id] ?? [] as $hook) {
            $hook($id, $value);
        }
    }

    public function dispatchResolvingHooks(string $id): void
    {
        if (!$this->hasHooks) {
            return;
        }

        foreach ($this->onResolvingHooks[$id] ?? [] as $hook) {
            $hook($id);
        }
    }

    /**
     * Enable or disable lazy loading and invalidate resolution configuration
     * when the option changes, preserving the established container contract.
     */
    public function enableLazyLoading(bool $lazy): void
    {
        $this->checkIfLocked();
        if ($this->lazyLoading === $lazy) {
            return;
        }

        $this->lazyLoading = $lazy;
        $this->invalidateResolutionConfiguration();
    }

    public function enableMethodAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        if ($this->enableMethodAttribute === $enable) {
            return;
        }

        $this->enableMethodAttribute = $enable;
        $this->invalidateResolutionConfiguration();
    }

    public function enablePropertyAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        if ($this->enablePropertyAttribute === $enable) {
            return;
        }

        $this->enablePropertyAttribute = $enable;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * @param array<string, mixed> $instances
     */
    public function enterScope(string $scope, array $instances = []): void
    {
        if ($scope === $this->currentScope || in_array($scope, $this->scopeStack, true)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $this->scopeStack[] = $this->currentScope;
        $this->currentScope = $scope;
        if ($instances !== []) {
            $this->scopeSeeds[$scope] = $instances;
        }
    }

    public function fetchInstanceOrValue(mixed $value): mixed
    {
        return $value instanceof ClassResolution ? $value->instance : $value;
    }

    /**
     * Read an instance seeded into the active scope without consulting global
     * definitions or lifetime metadata.
     *
     * @internal
     */
    public function findScopeSeed(string $id, mixed &$value): bool
    {
        if ($this->scopeSeeds === []) {
            return false;
        }

        $seeds = $this->scopeSeeds[$this->currentScope] ?? null;
        if (!is_array($seeds) || !array_key_exists($id, $seeds)) {
            return false;
        }

        $value = $seeds[$id];

        return true;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * @return array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}>
     */
    public function getAllDefinitionMeta(): array
    {
        return $this->definitionMeta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getClassResource(): array
    {
        return $this->classResource;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClassResourceFor(string $class): array
    {
        return $this->classResource[$class] ?? [];
    }

    /**
     * @return array<string, array{on: callable, params: array<int|string, mixed>}>
     */
    public function getClosureResource(): array
    {
        return $this->closureResource;
    }

    /**
     * @return array{on: callable, params: array<int|string, mixed>}|null
     */
    public function getClosureResourceEntry(string $alias): ?array
    {
        return $this->closureResource[$alias] ?? null;
    }

    public function getCompiledResolver(string $id): ?Closure
    {
        return isset($this->compiledResolverIds[$id]) ? $this->compiledResolver : null;
    }

    public function getContextualBinding(string $consumer, string $dependency): mixed
    {
        return $this->contextualBindings[$consumer][$dependency] ?? null;
    }

    /**
     * @return array<string, array<int, string>>
     * @internal
     */
    public function getContextualBindingShape(): array
    {
        $shape = [];
        foreach ($this->contextualBindings as $consumer => $bindings) {
            $dependencies = array_keys($bindings);
            sort($dependencies, SORT_STRING);
            $shape[$consumer] = $dependencies;
        }
        ksort($shape, SORT_STRING);

        return $shape;
    }

    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    public function getDefinitionCache(): ?CacheItemPoolInterface
    {
        return $this->definitionCache;
    }

    public function getDefinitionLifetime(string $id): LifetimeEnum
    {
        $lifetime = $this->definitionMeta[$id]['lifetime'] ?? LifetimeEnum::Singleton;
        $env = $this->environment;

        if ($env !== null && isset($this->definitionMetaByEnv[$env][$id]['lifetime'])) {
            return $this->definitionMetaByEnv[$env][$id]['lifetime'];
        }

        return $lifetime;
    }

    /**
     * @return array{lifetime: LifetimeEnum, tags: array<int, string>}
     */
    public function getDefinitionMeta(string $id): array
    {
        $meta = $this->definitionMeta[$id] ?? ['lifetime' => LifetimeEnum::Singleton, 'tags' => []];
        $env = $this->environment;

        if ($env !== null && isset($this->definitionMetaByEnv[$env][$id])) {
            $override = $this->definitionMetaByEnv[$env][$id];
            if (array_key_exists('lifetime', $override)) {
                $meta['lifetime'] = $override['lifetime'];
            }
            if (array_key_exists('tags', $override)) {
                $meta['tags'] = $override['tags'];
            }
        }

        return $meta;
    }

    public function getEnvConcrete(?string $interface): ?string
    {
        if ($this->environment === null || $interface === null) {
            return null;
        }

        return $this->conditionalBindings[$this->environment][$interface] ?? null;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function getFunctionDefinition(string $id): mixed
    {
        return $this->functionReference[$id] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFunctionReference(): array
    {
        return $this->functionReference;
    }

    /**
     * @return array<int, string>
     */
    public function getIdsByTag(string $tag): array
    {
        $ids = $this->tagIndex[$tag] ?? [];
        $env = $this->environment;

        if ($env === null) {
            return array_keys($ids);
        }

        foreach ($this->tagIndexByEnv[$env][$tag] ?? [] as $id => $_) {
            $ids[$id] = true;
        }

        foreach ($this->tagOverrideIdsByEnv[$env] ?? [] as $id => $_) {
            $override = $this->definitionMetaByEnv[$env][$id] ?? null;
            if (!is_array($override) || !array_key_exists('tags', $override)) {
                continue;
            }

            if (!in_array($tag, $override['tags'], true)) {
                unset($ids[$id]);
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<int, class-string>
     * @internal
     */
    public function getRegisteredAttributeTypes(): array
    {
        return $this->attributeRegistry?->types() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getResolved(): array
    {
        return $this->resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResolvedDefinition(): array
    {
        return $this->resolvedDefinition;
    }

    public function getResolvedDefinitionEntry(string $key): mixed
    {
        return $this->resolvedDefinition[$key] ?? null;
    }

    public function getResolvedEntry(string $key): mixed
    {
        return $this->resolved[$key] ?? null;
    }

    /**
     * @return array<int, string>
     * @internal
     */
    public function getResolvedHookIds(): array
    {
        return array_keys($this->onResolvedHooks);
    }

    /**
     * @return array<string, ClassResolution>
     */
    public function getResolvedResource(): array
    {
        return $this->resolvedResource;
    }

    public function getResolvedResourceFor(string $class): ?ClassResolution
    {
        return $this->resolvedResource[$class] ?? null;
    }

    /** @internal */
    public function getResolvedScopedEntry(string $scope, string $id): mixed
    {
        return $this->resolvedScoped[$scope][$id] ?? null;
    }

    /** @internal */
    public function getResolvedSingletonEntry(string $key): mixed
    {
        return $this->resolvedSingleton[$key] ?? null;
    }

    /**
     * @return array<int, string>
     * @internal
     */
    public function getResolvingHookIds(): array
    {
        return array_keys($this->onResolvingHooks);
    }

    public function getScope(): string
    {
        return $this->currentScope;
    }

    /**
     * @return array<int, string>
     * @internal
     */
    public function getScopeLeaveHookScopes(): array
    {
        return array_keys($this->onScopeLeaveHooks);
    }

    public function hasClosureResource(string $alias): bool
    {
        return array_key_exists($alias, $this->closureResource);
    }

    /** @internal */
    public function hasCompiledResolvers(): bool
    {
        return $this->compiledResolver instanceof Closure;
    }

    /** @internal */
    public function hasContextualBinding(string $consumer, string $dependency): bool
    {
        return array_key_exists($dependency, $this->contextualBindings[$consumer] ?? []);
    }

    /** @internal */
    public function hasContextualBindings(): bool
    {
        return $this->contextualBindings !== [];
    }

    public function hasFunctionReference(string $id): bool
    {
        return isset($this->functionReference[$id]) || array_key_exists($id, $this->functionReference);
    }

    public function hasResolved(string $key): bool
    {
        return isset($this->resolved[$key]) || array_key_exists($key, $this->resolved);
    }

    public function hasResolvedDefinition(string $key): bool
    {
        return array_key_exists($key, $this->resolvedDefinition);
    }

    /** @internal */
    public function hasResolvedResource(string $class): bool
    {
        return array_key_exists($class, $this->resolvedResource);
    }

    /** @internal */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        return array_key_exists($id, $this->resolvedScoped[$scope] ?? []);
    }

    /** @internal */
    public function hasResolvedSingleton(string $key): bool
    {
        return array_key_exists($key, $this->resolvedSingleton);
    }

    /** @internal */
    public function hasScopeSeeds(): bool
    {
        return $this->scopeSeeds !== [];
    }

    public function isDefinitionCacheFailOpen(): bool
    {
        return $this->definitionCacheFailOpen;
    }

    public function isLazyLoading(): bool
    {
        return $this->lazyLoading;
    }

    public function isMethodAttributeEnabled(): bool
    {
        return $this->enableMethodAttribute;
    }

    public function isPropertyAttributeEnabled(): bool
    {
        return $this->enablePropertyAttribute;
    }

    /** @internal */
    public function isResolved(string $id): bool
    {
        return isset($this->resolvedIds[$id]);
    }

    public function isTracingEnabled(): bool
    {
        return $this->tracingEnabled;
    }

    public function leaveScope(): void
    {
        $scope = $this->currentScope;
        if ($this->hasHooks) {
            foreach ($this->onScopeLeaveHooks[$scope] ?? [] as $hook) {
                $hook($scope, $this->container);
            }
        }

        $this->clearScopeResolvedEntries($scope);
        unset($this->scopeSeeds[$scope]);
        $previous = array_pop($this->scopeStack);
        $this->currentScope = is_string($previous) ? $previous : 'root';
    }

    public function lock(): void
    {
        $this->isLocked = true;
    }

    /**
     * Create a short PSR-6-safe cache key while reusing stable prefix hashes.
     */
    public function makeDefinitionCacheKey(string $definition): string
    {
        $this->definitionCachePrefix ??= 'imx.'
            . substr(hash('sha256', $this->alias), 0, 16)
            . '.' . substr(
                hash(
                    'sha256',
                    ($this->definitionCacheGeneration ?? 'default') . "\0" . $this->definitionCacheRevision,
                ),
                0,
                16,
            )
            . '.';

        return $this->definitionCachePrefix
            . substr(hash('sha256', $definition . "\0" . ($this->environment ?? 'default')), 0, 16);
    }

    /** @internal */
    public function markResolved(string $id): void
    {
        $this->resolvedIds[$id] = true;
    }

    /** @internal */
    public function notifyConfigurationMutation(): void
    {
        if ($this->configurationMutationListener instanceof Closure) {
            ($this->configurationMutationListener)();
        }
    }

    public function onResolved(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onResolvedHooks[$id][] = $hook;
    }

    public function onResolving(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onResolvingHooks[$id][] = $hook;
    }

    public function onScopeLeave(string $scope, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onScopeLeaveHooks[$scope][] = $hook;
    }

    public function removeDefinition(string $id): void
    {
        $this->checkIfLocked();
        if (!array_key_exists($id, $this->functionReference)
            && !array_key_exists($id, $this->closureResource)
        ) {
            return;
        }

        foreach ($this->definitionMeta[$id]['tags'] ?? [] as $tag) {
            unset($this->tagIndex[$tag][$id]);
        }
        foreach ($this->definitionMetaByEnv as $env => $definitions) {
            foreach ($definitions[$id]['tags'] ?? [] as $tag) {
                unset($this->tagIndexByEnv[$env][$tag][$id]);
            }
            unset($this->definitionMetaByEnv[$env][$id], $this->tagOverrideIdsByEnv[$env][$id]);
        }
        unset(
            $this->functionReference[$id],
            $this->closureResource[$id],
            $this->definitionMeta[$id],
        );
        $this->invalidateDefinition($id);
    }

    public function resetScope(): void
    {
        $this->clearScopeResolvedEntries($this->currentScope);
        $this->scopeStack = [];
        $this->currentScope = 'root';
        $this->resolvedScoped = [];
        $this->scopeSeeds = [];
    }

    public function rotateDefinitionCacheGeneration(): void
    {
        ++$this->definitionCacheRevision;
        $this->definitionCachePrefix = null;
    }

    public function setAlias(string $alias): void
    {
        $this->checkIfLocked();
        if ($this->alias === $alias) {
            return;
        }

        $this->alias = $alias;
        $this->definitionCachePrefix = null;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * @param array<string, mixed> $ids
     * @internal
     */
    public function setCompiledResolver(Closure $resolver, array $ids): void
    {
        $this->checkIfLocked();
        $this->compiledResolver = $resolver;
        $this->compiledResolverIds = $ids;
    }

    /**
     * @param (Closure(): void)|null $listener
     * @internal
     */
    public function setConfigurationMutationListener(?Closure $listener): void
    {
        $this->configurationMutationListener = $listener;
    }

    public function setContextualBinding(string $consumer, string $dependency, mixed $give): void
    {
        $this->checkIfLocked();
        if (array_key_exists($dependency, $this->contextualBindings[$consumer] ?? [])
            && $this->contextualBindings[$consumer][$dependency] === $give
        ) {
            return;
        }

        $this->contextualBindings[$consumer][$dependency] = $give;
        $this->invalidateResolutionConfiguration();
    }

    public function setDefaultMethod(?string $method): void
    {
        $this->checkIfLocked();
        if ($this->defaultMethod === $method) {
            return;
        }

        $this->defaultMethod = $method;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * Register a definition and its metadata as one mutation so resolution
     * state and compiled artifacts are invalidated exactly once.
     *
     * @param array<int, string> $tags
     */
    public function setDefinition(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $this->checkIfLocked();
        $normalizedTags = array_map(static fn(mixed $tag): string => (string) $tag, $tags);
        $meta = ['lifetime' => $lifetime, 'tags' => $normalizedTags];
        $definitionChanged = !array_key_exists($id, $this->functionReference)
            || $this->functionReference[$id] !== $definition;
        $metaChanged = ($this->definitionMeta[$id] ?? null) !== $meta;

        if (!$definitionChanged && !$metaChanged) {
            return;
        }

        $oldTags = $this->definitionMeta[$id]['tags'] ?? [];
        $this->functionReference[$id] = $definition;
        $this->definitionMeta[$id] = $meta;
        if ($oldTags !== $normalizedTags) {
            $this->refreshBaseTagIndex($id, $oldTags, $normalizedTags);
        }
        $this->invalidateDefinition($id);
    }

    public function setDefinitionCache(
        CacheItemPoolInterface $cache,
        ?string $generation = null,
        bool $failOpen = true,
    ): void {
        $this->checkIfLocked();
        if ($generation === '') {
            throw new ContainerException('Definition cache generation cannot be empty.');
        }

        if ($this->definitionCache === $cache
            && ($generation === null || $generation === $this->definitionCacheGeneration)
            && $this->definitionCacheFailOpen === $failOpen
        ) {
            return;
        }

        $this->notifyConfigurationMutation();

        $this->definitionCache = $cache;
        $this->definitionCacheFailOpen = $failOpen;
        if ($generation !== null && $generation !== $this->definitionCacheGeneration) {
            $this->definitionCacheGeneration = $generation;
            $this->definitionCacheRevision = 0;
            $this->definitionCachePrefix = null;
        }
    }

    /**
     * @param array{lifetime?: LifetimeEnum, tags?: array<int, scalar|null>} $meta
     */
    public function setDefinitionMeta(string $id, array $meta): void
    {
        $this->checkIfLocked();
        $normalized = $meta + ['lifetime' => LifetimeEnum::Singleton, 'tags' => []];
        $normalizedTags = array_map(static fn(mixed $tag): string => (string) $tag, $normalized['tags']);
        $normalized['tags'] = $normalizedTags;
        $oldTags = $this->definitionMeta[$id]['tags'] ?? [];

        if (($this->definitionMeta[$id] ?? null) === $normalized) {
            return;
        }

        $this->definitionMeta[$id] = $normalized;
        $this->refreshResolvedSingletonIndex($id);
        $this->refreshBaseTagIndex($id, $oldTags, $normalizedTags);
        $this->invalidateDefinition($id);
    }

    /**
     * @param array{lifetime?: LifetimeEnum, tags?: array<int, scalar|null>} $meta
     */
    public function setDefinitionMetaForEnv(string $env, string $id, array $meta): void
    {
        $this->checkIfLocked();
        $existing = $this->definitionMetaByEnv[$env][$id] ?? [];
        $normalized = [];

        if (array_key_exists('lifetime', $meta)) {
            $normalized['lifetime'] = $meta['lifetime'];
        }
        if (array_key_exists('tags', $meta)) {
            $normalized['tags'] = array_map(static fn(mixed $tag): string => (string) $tag, $meta['tags']);
        }
        if ($normalized === [] || $existing === ($normalized + $existing)) {
            return;
        }

        $this->definitionMetaByEnv[$env][$id] = $normalized + $existing;
        if ($this->environment === $env && array_key_exists('lifetime', $normalized)) {
            $this->refreshResolvedSingletonIndex($id);
        }
        if (array_key_exists('tags', $normalized)) {
            $oldTags = $existing['tags'] ?? [];
            $this->refreshEnvTagIndex($env, $id, $oldTags, $normalized['tags']);
            $this->tagOverrideIdsByEnv[$env][$id] = true;
        }
        $this->invalidateDefinition($id);
    }

    public function setEnvironment(string $env): void
    {
        if ($this->environment === $env) {
            return;
        }

        $this->checkIfLocked();
        $this->environment = $env;
        $this->invalidateResolutionConfiguration();
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->currentScope = 'root';
    }

    public function setFunctionReference(string $id, mixed $definition): void
    {
        $this->checkIfLocked();
        if (array_key_exists($id, $this->functionReference)
            && $this->functionReference[$id] === $definition
        ) {
            return;
        }

        $this->functionReference[$id] = $definition;
        $this->invalidateDefinition($id);
    }

    public function setResolved(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        if ($this->getDefinitionLifetime($id) === LifetimeEnum::Singleton) {
            $this->resolvedSingleton[$id] = $this->fetchInstanceOrValue($value);

            return;
        }

        unset($this->resolvedSingleton[$id]);
    }

    public function setResolvedDefinition(string $defName, mixed $value): void
    {
        $this->resolvedDefinition[$defName] = $value;
    }

    public function setResolvedResource(string $className, ClassResolution $data): void
    {
        $this->resolvedResource[$className] = $data;
    }

    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        $this->resolvedScoped[$scope][$id] = $value;
    }

    public function setScope(string $scope): void
    {
        $this->currentScope = $scope;
    }

    public function shouldPersistDefinitionValue(mixed $value): bool
    {
        return $this->isSafeCachedDefinitionValue($value);
    }

    public function tracer(): DebugTracer
    {
        return $this->tracer ??= new DebugTracer(
            stateListener: function (bool $enabled): void {
                $this->tracingEnabled = $enabled;
            },
        );
    }

    public function unsetResolvedResource(string $className): void
    {
        unset($this->resolvedResource[$className]);
    }

    private function checkIfLocked(): void
    {
        if ($this->isLocked) {
            throw new ContainerException('Container is locked! Unable to set/modify any value.');
        }
    }

    private function clearScopeResolvedEntries(string $scope): void
    {
        unset($this->resolvedScoped[$scope]);
    }

    private function isSafeCachedDefinitionValue(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn($item) => $this->isSafeCachedDefinitionValue($item));
    }

    /**
     * @param array<int, string> $oldTags
     * @param array<int, string> $newTags
     */
    private function refreshBaseTagIndex(string $id, array $oldTags, array $newTags): void
    {
        foreach ($oldTags as $tag) {
            unset($this->tagIndex[$tag][$id]);
            if (($this->tagIndex[$tag] ?? []) === []) {
                unset($this->tagIndex[$tag]);
            }
        }

        foreach ($newTags as $tag) {
            $this->tagIndex[$tag][$id] = true;
        }
    }

    /**
     * @param array<int, string> $oldTags
     * @param array<int, string> $newTags
     */
    private function refreshEnvTagIndex(string $env, string $id, array $oldTags, array $newTags): void
    {
        foreach ($oldTags as $tag) {
            unset($this->tagIndexByEnv[$env][$tag][$id]);
            if (($this->tagIndexByEnv[$env][$tag] ?? []) === []) {
                unset($this->tagIndexByEnv[$env][$tag]);
            }
        }

        foreach ($newTags as $tag) {
            $this->tagIndexByEnv[$env][$tag][$id] = true;
        }
    }

    private function refreshResolvedSingletonIndex(string $id): void
    {
        if ($this->getDefinitionLifetime($id) === LifetimeEnum::Singleton
            && array_key_exists($id, $this->resolved)
        ) {
            $this->resolvedSingleton[$id] = $this->fetchInstanceOrValue($this->resolved[$id]);

            return;
        }

        unset($this->resolvedSingleton[$id]);
    }
}
