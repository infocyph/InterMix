<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Resolver\Concerns\InvalidatesRepositoryState;
use Infocyph\InterMix\DI\Resolver\Concerns\ManagesDefinitionCache;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesMissingServices;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Container\ContainerInterface;

/**
 * Central storage for the container's definitions, resolved instances, and
 * resolution configuration.
 */
class Repository
{
    use InvalidatesRepositoryState;
    use ManagesDefinitionCache;
    use ResolvesMissingServices;

    /** @var array<string, array<int, callable(string,Container): void>> */
    protected array $onScopeLeaveHooks = [];

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

    /** @var array<string, array{lifetime: LifetimeEnum, alias: AliasDefinition|null}> */
    private array $definitionLifetimeCache = [];

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

    /** @var array<string, bool> */
    private array $scopeSeedAllowedIds = [];

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
        $this->functionReference[RuntimeContainerInterface::class] = $container;
    }

    /**
     * Store changed constructor, method or property metadata and invalidate derived class state.
     *
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
     * Register a callable resource and invalidate previously resolved values for its alias.
     *
     * @param array<int|string, mixed> $params
     */
    public function addClosureResource(string $alias, callable $function, array $params = []): void
    {
        $this->checkIfLocked();
        $this->closureResource[$alias] = ['on' => $function, 'params' => $params];
        $this->invalidateDefinition($alias);
    }

    /**
     * Reuse a container-owned registry, creating it only when attribute configuration is needed.
     */
    public function attributeRegistry(): AttributeRegistry
    {
        return $this->attributeRegistry ??= new AttributeRegistry($this->container);
    }

    /**
     * Store an environment-specific implementation and invalidate affected resolution
     * configuration.
     */
    public function bindInterfaceForEnv(string $env, string $interface, string $concrete): void
    {
        $this->checkIfLocked();
        if (($this->conditionalBindings[$env][$interface] ?? null) === $concrete) {
            return;
        }

        $this->conditionalBindings[$env][$interface] = $concrete;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * Expose the repository owner for internal dependency resolution and callback dispatch.
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Copy declarations, resources and policies without copying resolved instances or scope values.
     *
     * @internal
     */
    public function copyConfigurationTo(Repository $target): void
    {
        $target->classResource = $this->classResource;
        $target->closureResource = $this->closureResource;
        $target->conditionalBindings = $this->conditionalBindings;
        $target->contextualBindings = $this->contextualBindings;
        $target->defaultMethod = $this->defaultMethod;
        $target->definitionCache = $this->definitionCache;
        $target->definitionCacheEligibleIds = $this->definitionCacheEligibleIds;
        $target->definitionCacheExplicitOnly = $this->definitionCacheExplicitOnly;
        $target->definitionCacheFailOpen = $this->definitionCacheFailOpen;
        $target->definitionCacheGeneration = $this->definitionCacheGeneration;
        $target->definitionCacheNamespace = $this->definitionCacheNamespace;
        $target->definitionCachePrefix = null;
        $target->definitionCacheRevision = $this->definitionCacheRevision;
        $target->definitionMeta = $this->definitionMeta;
        $target->definitionMetaByEnv = $this->definitionMetaByEnv;
        $target->enableMethodAttribute = $this->enableMethodAttribute;
        $target->enablePropertyAttribute = $this->enablePropertyAttribute;
        $target->environment = $this->environment;
        $target->functionReference = $this->functionReference;
        $target->functionReference[ContainerInterface::class] = $target->container;
        $target->functionReference[RuntimeContainerInterface::class] = $target->container;
        $target->hasHooks = $this->hasHooks;
        $target->hasPropertyResources = $this->hasPropertyResources;
        $target->lazyLoading = $this->lazyLoading;
        $target->onResolvedHooks = $this->onResolvedHooks;
        $target->onResolvingHooks = $this->onResolvingHooks;
        $target->onScopeLeaveHooks = $this->onScopeLeaveHooks;
        $target->tagIndex = $this->tagIndex;
        $target->tagIndexByEnv = $this->tagIndexByEnv;
        $target->tagOverrideIdsByEnv = $this->tagOverrideIdsByEnv;

        if ($this->attributeRegistry !== null) {
            foreach ($this->attributeRegistry->registrations() as $attribute => $resolver) {
                $target->attributeRegistry()->register($attribute, $resolver);
            }
        }

        if ($this->tracer !== null) {
            $target->tracer()
                ->setCaptureLocation($this->tracer->isCaptureLocationEnabled())
                ->setLevel($this->tracer->level());
        }
    }

    /**
     * Invoke registered callbacks for this identifier after successful construction.
     */
    public function dispatchResolvedHooks(string $id, mixed $value): void
    {
        if (!$this->hasHooks) {
            return;
        }

        foreach ($this->onResolvedHooks[$id] ?? [] as $hook) {
            $hook($id, $value);
        }
    }

    /**
     * Invoke registered callbacks for this identifier before construction.
     */
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

    /**
     * Change method attribute policy and invalidate cached resolution configuration.
     */
    public function enableMethodAttribute(bool $enable): void
    {
        $this->checkIfLocked();
        if ($this->enableMethodAttribute === $enable) {
            return;
        }

        $this->enableMethodAttribute = $enable;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * Change property attribute policy and invalidate cached resolution configuration.
     */
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
     * Push sequential scope state and retain supplied seeds only for that execution.
     *
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

    /**
     * Unwrap construction metadata while preserving literal values unchanged.
     */
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

    /**
     * Expose the container namespace used by persistent cache identity.
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Expose base lifetime and tag declarations before environment overrides.
     *
     * @return array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}>
     */
    public function getAllDefinitionMeta(): array
    {
        return $this->definitionMeta;
    }

    /**
     * Expose explicit class construction, method and property resources.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getClassResource(): array
    {
        return $this->classResource;
    }

    /**
     * Read explicit resources for one class, using an empty map when none are registered.
     *
     * @return array<string, mixed>
     */
    public function getClassResourceFor(string $class): array
    {
        return $this->classResource[$class] ?? [];
    }

    /**
     * Expose registered callable resources and their supplied argument overrides.
     *
     * @return array<string, array{on: callable, params: array<int|string, mixed>}>
     */
    public function getClosureResource(): array
    {
        return $this->closureResource;
    }

    /**
     * Read a callable resource by alias, or null when absent.
     *
     * @return array{on: callable, params: array<int|string, mixed>}|null
     */
    public function getClosureResourceEntry(string $alias): ?array
    {
        return $this->closureResource[$alias] ?? null;
    }

    /**
     * Return the installed legacy resolver only for identifiers included in its compiled set.
     */
    public function getCompiledResolver(string $id): ?Closure
    {
        return isset($this->compiledResolverIds[$id]) ? $this->compiledResolver : null;
    }

    /**
     * Read a consumer-specific dependency override; null also represents absence.
     */
    public function getContextualBinding(string $consumer, string $dependency): mixed
    {
        return $this->contextualBindings[$consumer][$dependency] ?? null;
    }

    /**
     * Expose sorted consumer and dependency identifiers without executable binding values.
     *
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

    /**
     * Expose the optional method selected after class construction.
     */
    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    /**
     * Resolve effective lifetime through aliases and expose the alias wrapper when present.
     */
    public function getDefinitionLifetime(string $id, ?AliasDefinition &$alias = null): LifetimeEnum
    {
        $cached = $this->definitionLifetimeFor($id);
        $alias = $cached['alias'];

        return $cached['lifetime'];
    }

    /**
     * Merge active environment overrides onto base lifetime and tag declarations.
     *
     * @return array{lifetime: LifetimeEnum, tags: array<int, string>}
     */
    public function getDefinitionMeta(string $id): array
    {
        $meta = $this->definitionMeta[$id] ?? ['lifetime' => LifetimeEnum::Singleton, 'tags' => []];
        $env = $this->environment;

        return $env === null ? $meta : ($this->definitionMetaByEnv[$env][$id] ?? []) + $meta;
    }

    /**
     * Read the active environment's interface implementation, or null when no binding applies.
     */
    public function getEnvConcrete(?string $interface): ?string
    {
        if ($this->environment === null || $interface === null) {
            return null;
        }

        return $this->conditionalBindings[$this->environment][$interface] ?? null;
    }

    /**
     * Expose the selected environment, or null before one is configured.
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Read a registered recipe; use declaration presence checks to distinguish a null literal.
     */
    public function getFunctionDefinition(string $id): mixed
    {
        return $this->functionReference[$id] ?? null;
    }

    /**
     * Expose raw registered recipes without resolving their values.
     *
     * @return array<string, mixed>
     */
    public function getFunctionReference(): array
    {
        return $this->functionReference;
    }

    /**
     * Select identifiers using base tags and the active environment's tag overrides.
     *
     * @return array<int, string>
     */
    public function getIdsByTag(string $tag): array
    {
        $ids = $this->tagIndex[$tag] ?? [];
        $env = $this->environment;

        if ($env === null) {
            return array_map(
                static fn(int|string $id): string => (string) $id,
                array_keys($ids),
            );
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
     * Expose attribute-to-resolver mappings without initializing an unused registry.
     *
     * @return array<class-string, class-string<\Infocyph\InterMix\DI\Attribute\AttributeResolverInterface>>
     * @internal
     */
    public function getRegisteredAttributeResolvers(): array
    {
        return $this->attributeRegistry?->registrations() ?? [];
    }

    /**
     * List explicitly registered attribute types without initializing an unused registry.
     *
     * @return array<int, class-string>
     * @internal
     */
    public function getRegisteredAttributeTypes(): array
    {
        return $this->attributeRegistry?->types() ?? [];
    }

    /**
     * Expose cached definition results, including construction metadata where retained.
     *
     * @return array<string, mixed>
     */
    public function getResolved(): array
    {
        return $this->resolved;
    }

    /**
     * Expose the cache of resolved recipe values.
     *
     * @return array<string, mixed>
     */
    public function getResolvedDefinition(): array
    {
        return $this->resolvedDefinition;
    }

    /**
     * Read a cached recipe value; null also represents a missing cache entry.
     */
    public function getResolvedDefinitionEntry(string $key): mixed
    {
        return $this->resolvedDefinition[$key] ?? null;
    }

    /**
     * Read a cached definition result; null also represents a missing cache entry.
     */
    public function getResolvedEntry(string $key): mixed
    {
        return $this->resolved[$key] ?? null;
    }

    /**
     * List identifiers with post-construction callbacks for artifact fallback classification.
     *
     * @return array<int, string>
     * @internal
     */
    public function getResolvedHookIds(): array
    {
        return array_map(
            static fn(int|string $id): string => (string) $id,
            array_keys($this->onResolvedHooks),
        );
    }

    /**
     * Expose cached class construction results and any associated method returns.
     *
     * @return array<string, ClassResolution>
     */
    public function getResolvedResource(): array
    {
        return $this->resolvedResource;
    }

    /**
     * Read cached construction metadata for one class, or null when absent.
     */
    public function getResolvedResourceFor(string $class): ?ClassResolution
    {
        return $this->resolvedResource[$class] ?? null;
    }

    /**
     * Read a named scope's result; null also represents a missing cached entry.
     *
     * @internal
     */
    public function getResolvedScopedEntry(string $scope, string $id): mixed
    {
        return $this->resolvedScoped[$scope][$id] ?? null;
    }

    /**
     * Read the singleton index; use its presence check to distinguish cached null values.
     *
     * @internal
     */
    public function getResolvedSingletonEntry(string $key): mixed
    {
        return $this->resolvedSingleton[$key] ?? null;
    }

    /**
     * List identifiers with pre-construction callbacks for artifact fallback classification.
     *
     * @return array<int, string>
     * @internal
     */
    public function getResolvingHookIds(): array
    {
        return array_map(
            static fn(int|string $id): string => (string) $id,
            array_keys($this->onResolvingHooks),
        );
    }

    /**
     * Expose the sequential execution's active scope name.
     */
    public function getScope(): string
    {
        return $this->currentScope;
    }

    /**
     * List scope names with registered cleanup callbacks.
     *
     * @return array<int, string>
     * @internal
     */
    public function getScopeLeaveHookScopes(): array
    {
        return array_keys($this->onScopeLeaveHooks);
    }

    /**
     * Check callable resource presence without executing the resource.
     */
    public function hasClosureResource(string $alias): bool
    {
        return array_key_exists($alias, $this->closureResource);
    }

    /**
     * Check whether a legacy compiled dispatcher is installed.
     *
     * @internal
     */
    public function hasCompiledResolvers(): bool
    {
        return $this->compiledResolver instanceof Closure;
    }

    /**
     * Check consumer-specific override presence, preserving literal null bindings.
     *
     * @internal
     */
    public function hasContextualBinding(string $consumer, string $dependency): bool
    {
        return array_key_exists($dependency, $this->contextualBindings[$consumer] ?? []);
    }

    /**
     * Check whether any consumer-specific overrides are configured.
     *
     * @internal
     */
    public function hasContextualBindings(): bool
    {
        return $this->contextualBindings !== [];
    }

    /**
     * Check declaration presence, including identifiers bound to literal null.
     */
    public function hasFunctionReference(string $id): bool
    {
        return isset($this->functionReference[$id]) || array_key_exists($id, $this->functionReference);
    }

    /**
     * Check cached result presence, including results whose value is null.
     */
    public function hasResolved(string $key): bool
    {
        return isset($this->resolved[$key]) || array_key_exists($key, $this->resolved);
    }

    /**
     * Check recipe-cache presence independently of the cached value.
     */
    public function hasResolvedDefinition(string $key): bool
    {
        return array_key_exists($key, $this->resolvedDefinition);
    }

    /**
     * Check whether class construction metadata has been cached.
     *
     * @internal
     */
    public function hasResolvedResource(string $class): bool
    {
        return array_key_exists($class, $this->resolvedResource);
    }

    /**
     * Check named-scope cache presence independently of a possibly null value.
     *
     * @internal
     */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        return array_key_exists($id, $this->resolvedScoped[$scope] ?? []);
    }

    /**
     * Check singleton index presence independently of a possibly null value.
     *
     * @internal
     */
    public function hasResolvedSingleton(string $key): bool
    {
        return array_key_exists($key, $this->resolvedSingleton);
    }

    /**
     * Check whether sequential execution retains any supplied scope seeds.
     *
     * @internal
     */
    public function hasScopeSeeds(): bool
    {
        return $this->scopeSeeds !== [];
    }

    /**
     * Check how future legacy class bindings are normalized.
     */
    public function isLazyLoading(): bool
    {
        return $this->lazyLoading;
    }

    /**
     * Check whether reflected method attributes participate in injection.
     */
    public function isMethodAttributeEnabled(): bool
    {
        return $this->enableMethodAttribute;
    }

    /**
     * Check whether reflected property attributes participate in injection.
     */
    public function isPropertyAttributeEnabled(): bool
    {
        return $this->enablePropertyAttribute;
    }

    /**
     * Check completed resolution markers without constructing the entry.
     *
     * @internal
     */
    public function isResolved(string $id): bool
    {
        return isset($this->resolvedIds[$id]);
    }

    /**
     * Memoize seed eligibility for direct declared scoped entries; aliases cannot accept seeds.
     *
     * @internal
     */
    public function isScopeSeedAllowed(string $id): bool
    {
        return $this->scopeSeedAllowedIds[$id] ??= array_key_exists($id, $this->functionReference)
            && !$this->functionReference[$id] instanceof AliasDefinition
            && $this->getDirectDefinitionLifetime($id) === LifetimeEnum::Scoped;
    }

    /**
     * Read the tracer's enabled-state flag without initializing a tracer on the hot path.
     */
    public function isTracingEnabled(): bool
    {
        return $this->tracingEnabled;
    }

    /**
     * Run sequential cleanup hooks, release scoped results and seeds, then restore the parent.
     */
    public function leaveScope(): void
    {
        $scope = $this->currentScope;
        if ($this->hasHooks) {
            foreach ($this->onScopeLeaveHooks[$scope] ?? [] as $hook) {
                $hook($scope, $this->container);
            }
        }

        unset($this->resolvedScoped[$scope]);
        unset($this->scopeSeeds[$scope]);
        $previous = array_pop($this->scopeStack);
        $this->currentScope = is_string($previous) ? $previous : 'root';
    }

    /**
     * Freeze repository configuration while allowing ordinary runtime resolution.
     */
    public function lock(): void
    {
        $this->isLocked = true;
    }

    /**
     * Record successful resolution separately from the cached result's value.
     *
     * @internal
     */
    public function markResolved(string $id): void
    {
        $this->resolvedIds[$id] = true;
    }

    /**
     * Notify the builder that configuration-derived graph state must be refreshed.
     *
     * @internal
     */
    public function notifyConfigurationMutation(): void
    {
        if ($this->configurationMutationListener instanceof Closure) {
            ($this->configurationMutationListener)();
        }
    }

    /**
     * Append a post-construction callback while enforcing the configuration lock.
     */
    public function onResolved(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onResolvedHooks[$id][] = $hook;
    }

    /**
     * Append a pre-construction callback while enforcing the configuration lock.
     */
    public function onResolving(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onResolvingHooks[$id][] = $hook;
    }

    /**
     * Append cleanup work for a named scope while enforcing the configuration lock.
     */
    public function onScopeLeave(string $scope, callable $hook): void
    {
        $this->checkIfLocked();
        $this->notifyConfigurationMutation();
        $this->hasHooks = true;
        $this->onScopeLeaveHooks[$scope][] = $hook;
    }

    /**
     * Remove a declaration, its tag indexes and eligibility, then invalidate derived results.
     */
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
            $this->definitionCacheEligibleIds[$id],
            $this->definitionMeta[$id],
        );
        $this->invalidateDefinition($id);
    }

    /**
     * Discard all sequential scope caches and seeds and restore the root frame.
     */
    public function resetScope(): void
    {
        unset($this->resolvedScoped[$this->currentScope]);
        $this->scopeStack = [];
        $this->currentScope = 'root';
        $this->resolvedScoped = [];
        $this->scopeSeeds = [];
    }

    /**
     * Change the cache namespace and invalidate configuration-derived state.
     */
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
     * Install a legacy dispatcher with its supported identifier set while configuration is mutable.
     *
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
     * Install or remove the builder's graph-invalidation callback.
     *
     * @param (Closure(): void)|null $listener
     * @internal
     */
    public function setConfigurationMutationListener(?Closure $listener): void
    {
        $this->configurationMutationListener = $listener;
    }

    /**
     * Store a changed consumer-specific override and invalidate resolution configuration.
     */
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

    /**
     * Change the optional post-construction method and invalidate resolution configuration.
     */
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
            $this->refreshTagIndex($id, $oldTags, $normalizedTags, $this->tagIndex);
        }
        $this->invalidateDefinition($id);
    }

    /**
     * Normalize base lifetime and tags, refresh indexes and invalidate affected results.
     *
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
        $this->refreshTagIndex($id, $oldTags, $normalizedTags, $this->tagIndex);
        $this->invalidateDefinition($id);
    }

    /**
     * Merge provided environment overrides and refresh affected lifetime and tag indexes.
     *
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
            $this->tagIndexByEnv[$env] ??= [];
            $this->refreshTagIndex($id, $oldTags, $normalized['tags'], $this->tagIndexByEnv[$env]);
            $this->tagOverrideIdsByEnv[$env][$id] = true;
        }
        $this->invalidateDefinition($id);
    }

    /**
     * Select overrides, invalidate resolved state and reset sequential scope identity.
     */
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

    /**
     * Store a changed recipe and invalidate its previously derived resolution state.
     */
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

    /**
     * Cache a result and update its singleton index according to effective lifetime.
     */
    public function setResolved(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        if ($this->getDefinitionLifetime($id) === LifetimeEnum::Singleton) {
            $this->resolvedSingleton[$id] = $this->fetchInstanceOrValue($value);

            return;
        }

        unset($this->resolvedSingleton[$id]);
    }

    /**
     * Cache a recipe result, retaining null as a valid cached value.
     */
    public function setResolvedDefinition(string $defName, mixed $value): void
    {
        $this->resolvedDefinition[$defName] = $value;
    }

    /**
     * Cache constructed class metadata separately from definition-level results.
     */
    public function setResolvedResource(string $className, ClassResolution $data): void
    {
        $this->resolvedResource[$className] = $data;
    }

    /**
     * Store a result under its execution scope rather than in the singleton index.
     */
    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        $this->resolvedScoped[$scope][$id] = $value;
    }

    /**
     * Replace the sequential scope name without pushing or cleaning a frame.
     */
    public function setScope(string $scope): void
    {
        $this->currentScope = $scope;
    }

    /**
     * Initialize tracing once and keep the hot-path enabled flag synchronized with its level.
     */
    public function tracer(): DebugTracer
    {
        return $this->tracer ??= new DebugTracer(
            stateListener: function (bool $enabled): void {
                $this->tracingEnabled = $enabled;
            },
        );
    }

    /**
     * Discard cached construction metadata for one class.
     */
    public function unsetResolvedResource(string $className): void
    {
        unset($this->resolvedResource[$className]);
    }

    /**
     * Reject configuration mutation after the repository has been frozen.
     */
    protected function checkIfLocked(): void
    {
        if ($this->isLocked) {
            throw new ContainerException('Container is locked! Unable to set/modify any value.');
        }
    }

    private function getDirectDefinitionLifetime(string $id): LifetimeEnum
    {
        $lifetime = $this->definitionMeta[$id]['lifetime'] ?? LifetimeEnum::Singleton;
        $env = $this->environment;

        if ($env !== null && isset($this->definitionMetaByEnv[$env][$id]['lifetime'])) {
            return $this->definitionMetaByEnv[$env][$id]['lifetime'];
        }

        return $lifetime;
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

    /**
     * @param array<int, string> $oldTags
     * @param array<int, string> $newTags
     * @param array<string, array<string, bool>> $index
     */
    private function refreshTagIndex(string $id, array $oldTags, array $newTags, array &$index): void
    {
        foreach ($oldTags as $tag) {
            unset($index[$tag][$id]);
            if (($index[$tag] ?? []) === []) {
                unset($index[$tag]);
            }
        }
        foreach ($newTags as $tag) {
            $index[$tag][$id] = true;
        }
    }

    /** @return array{lifetime: LifetimeEnum, alias: AliasDefinition|null} */
    private function resolveDefinitionLifetime(string $id): array
    {
        $definition = $this->functionReference[$id] ?? null;
        if (!$definition instanceof AliasDefinition) {
            return [
                'lifetime' => $this->getDirectDefinitionLifetime($id),
                'alias' => null,
            ];
        }

        $alias = $definition;
        $current = $definition->target;
        $seen = [$id => true];

        while (($definition = $this->functionReference[$current] ?? null) instanceof AliasDefinition) {
            if (isset($seen[$current])) {
                throw new ContainerException("Circular alias dependency for '{$id}'.");
            }

            $seen[$current] = true;
            $current = $definition->target;
        }

        return [
            'lifetime' => $this->getDirectDefinitionLifetime($current),
            'alias' => $alias,
        ];
    }
}
