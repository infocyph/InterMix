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

/**
 * Central storage for the container’s definitions, resolved instances, etc.
 * Also includes optional toggles for:
 *   - environment-based overrides
 *   - lazy loading
 *   - debug mode
 *   - unified cache key generation
 */
class Repository
{
    use InvalidatesRepositoryState;
    use ResolvesMissingServices;

    private string $alias = 'default';

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

    /** @var array<string, array<string, mixed>> */
    private array $contextualBindings = [];

    private string $currentScope = 'root';

    private ?string $defaultMethod = null;

    private ?CacheItemPoolInterface $definitionCache = null;

    private bool $definitionCacheFailOpen = true;

    private ?string $definitionCacheGeneration = null;

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
     * Constructs a Repository instance.
     *
     * Initializes a new DebugTracer to track and log the execution flow
     * and interactions within the container. This aids in debugging and
     * tracing the service resolution process.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Stores a class resource, with a key that can be 'constructor', 'method', 'property'.
     *
     * The given data is stored under its class and resource-type keys.
     *
     * This method ensures that the container is not locked before making modifications.
     *
     * @param string $class The class name.
     * @param string $key The key for the class resource.
     * @param array<string, mixed> $data The data for the class resource.
     *
     * @throws ContainerException if the container is locked.
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
     * Adds a closure resource to the repository.
     *
     * This method stores a closure function with its associated alias and parameters
     * in the closure resources array. The closure can be later retrieved and executed
     * using its alias. Before adding the closure, it checks whether the container is
     * locked to prevent modifications.
     *
     * @param string $alias The alias for the closure.
     * @param callable $function The closure function to store.
     * @param array<int|string, mixed> $params Optional parameters for the closure.
     *
     * @throws ContainerException if the container is locked.
     */
    public function addClosureResource(string $alias, callable $function, array $params = []): void
    {
        $this->checkIfLocked();
        $this->closureResource[$alias] = ['on' => $function, 'params' => $params];
        $this->invalidateDefinition($alias);
    }

    /**
     * Retrieves the attribute registry instance.
     *
     * This method returns the attribute registry associated with the repository.
     * The attribute registry is responsible for managing and resolving attribute
     * definitions and their corresponding resolvers.
     *
     * @return AttributeRegistry The attribute registry instance.
     */
    public function attributeRegistry(): AttributeRegistry
    {
        return $this->attributeRegistry ??= new AttributeRegistry($this->container);
    }

    /**
     * Binds a concrete implementation to an interface for a specific environment.
     *
     * The given interface will be resolved to the given concrete implementation
     * only if the current environment matches the given environment.
     *
     * @param string $env the environment for which the binding should be applied
     * @param string $interface the interface to bind
     * @param string $concrete the concrete implementation to bind to
     *
     * @throws ContainerException if the container is locked
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
     * Enables or disables lazy loading for the repository.
     *
     * If lazy loading is enabled, some definitions or services
     * can be "lazy" and not resolved until explicitly requested the first time.
     *
     * @param bool $lazy whether to enable lazy loading
     *
     * @throws ContainerException if the container is locked
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
     * Enables or disables method attribute resolution.
     *
     * Method attributes are an InterMix feature that allows you to inject
     * values into class methods. This method enables or disables the
     * resolution of these attributes. If the container is locked, an exception
     * will be thrown.
     *
     * @param bool $enable true to enable method attribute resolution, false to disable
     *
     * @throws ContainerException if the container is locked.
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
     * Enables or disables property attribute resolution.
     *
     * Property attributes are an InterMix feature that allows you to inject
     * values into class properties. This method enables or disables the
     * resolution of these attributes. If the container is locked, an exception
     * will be thrown.
     *
     * @param bool $enable true to enable property attribute resolution, false to disable
     *
     * @throws ContainerException if the container is locked.
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
     * Enter a named logical scope.
     *
     * @param array<string, mixed> $instances
     * @throws ContainerException
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
     * @param mixed $value The value to extract the instance from.
     * @return mixed The instance or the original value.
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
     * Returns the alias of this definition repository.
     *
     * The alias is a unique identifier for this repository. It is used to
     * generate cache keys and to identify the repository when serializing
     * or unserializing the container.
     *
     * @return string the alias of this repository
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Return lifetime and tag metadata keyed by definition ID.
     *
     * @return array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}> An array of all the definition meta data.
     */
    public function getAllDefinitionMeta(): array
    {
        return $this->definitionMeta;
    }

    /**
     * Return registered constructor and method resources keyed by class.
     *
     * @return array<string, array<string, mixed>> the array of class resources
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
     * Return registered callables and arguments keyed by alias.
     *
     * @return array<string, array{on: callable, params: array<int|string, mixed>}> the array of closure resources
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
     * @return array<string, array<int, string>> Contextual binding coordinates.
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
     * Retrieves the default method to be called when resolving a class.
     *
     * Returns the default method name that is used when resolving a class
     * resource. If no default method is set, this method returns null.
     *
     * @return string|null the default method name, or null if no default is set
     */
    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    /** Return the optional PSR-6 definition cache. */
    public function getDefinitionCache(): ?CacheItemPoolInterface
    {
        return $this->definitionCache;
    }

    public function getDefinitionLifetime(string $id): LifetimeEnum
    {
        $lifetime = $this->definitionMeta[$id]['lifetime'] ?? LifetimeEnum::Singleton;
        $env = $this->environment;

        if ($env !== null
            && isset($this->definitionMetaByEnv[$env][$id]['lifetime'])
        ) {
            $lifetime = $this->definitionMetaByEnv[$env][$id]['lifetime'];
        }

        return $lifetime;
    }

    /**
     * Returns the meta data for the given definition.
     *
     * @param string $id The id of the definition to retrieve meta data for.
     * @return array{lifetime: LifetimeEnum, tags: array<int, string>} The meta data associated with the definition.
     *                                                                 - lifetime: The lifetime of the definition. Defaults to Lifetime::Singleton.
     *                                                                 - tags: An array of tags to associate with the definition. Defaults to an empty array.
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

    /**
     * Get the concrete implementation bound to an interface for the current environment.
     *
     * Return the environment-specific implementation when one is registered.
     *
     * @param string|null $interface the interface to get the concrete implementation for
     * @return string|null the concrete implementation for the given interface in the current environment
     */
    public function getEnvConcrete(?string $interface): ?string
    {
        if ($this->environment === null || $interface === null) {
            return null;
        }

        return $this->conditionalBindings[$this->environment][$interface] ?? null;
    }

    /**
     * Retrieve the current environment.
     *
     * @return string|null the environment name, or null if not set
     */
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
     * @return array<int, class-string> Registered attribute classes.
     * @internal
     */
    public function getRegisteredAttributeTypes(): array
    {
        return $this->attributeRegistry?->types() ?? [];
    }

    /**
     * Returns the array of resolved resources.
     *
     * This method returns the array of resolved resources, where each key is the
     * ID of the resource and the value is the resolved value (instance, closure, etc.).
     *
     * @return array the array of resolved resources
     */
    /**
     * @return array<string, mixed>
     */
    public function getResolved(): array
    {
        return $this->resolved;
    }

    /**
     * Returns the array of resolved definitions.
     *
     * This method returns the array of resolved definitions, where each key is the
     * definition name and the value is the resolved value of that definition.
     *
     * @return array the array of resolved definitions
     */
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
     * Return class resolution state keyed by class name.
     *
     * @return array<string, ClassResolution> the resolved class resources
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
     * Gets the current scope of the container.
     *
     * The scope is used to determine the lifetime of definitions. For example, if the
     * scope is set to 'request', definitions will be resolved once per request.
     *
     * @return string The current scope. Defaults to 'root'.
     */
    public function getScope(): string
    {
        return $this->currentScope;
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

    /**
     * @param string $consumer Consumer class name.
     * @param string $dependency Dependency class name.
     * @internal
     */
    public function hasContextualBinding(string $consumer, string $dependency): bool
    {
        return array_key_exists($dependency, $this->contextualBindings[$consumer] ?? []);
    }

    /**
     * Checks if a function reference exists for the given identifier.
     *
     * This method determines whether a function reference is present in the
     * repository for the provided function identifier.
     *
     * @param string $id The identifier of the function reference.
     * @return bool True if the function reference exists, false otherwise.
     */
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

    /**
     * Checks if lazy loading is enabled for the repository.
     *
     * @return bool whether lazy loading is enabled
     */
    public function isLazyLoading(): bool
    {
        return $this->lazyLoading;
    }

    /**
     * Returns whether method attributes are enabled.
     *
     * Method attributes are an InterMix feature that allows you to inject
     * values into class methods. This method returns true if method
     * attributes are enabled, and false otherwise.
     *
     * @return bool true if method attributes are enabled, false otherwise
     */
    public function isMethodAttributeEnabled(): bool
    {
        return $this->enableMethodAttribute;
    }

    /**
     * Returns whether property attributes are enabled.
     *
     * Property attributes are an InterMix feature that allows you to inject
     * values into class properties. This method returns true if property
     * attributes are enabled, and false otherwise.
     *
     * @return bool true if property attributes are enabled, false otherwise
     */
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

    /**
     * Locks the container from future modifications.
     *
     * Once the container is locked, no more definitions, values, or options can be set.
     * This method is useful for tests or other scenarios where you want to ensure that
     * the container does not change after it has been configured.
     */
    public function lock(): void
    {
        $this->isLocked = true;
    }

    /** Create a short, backend-neutral key without exposing definition metadata. */
    public function makeDefinitionCacheKey(string $definition): string
    {
        $environment = $this->environment ?? 'default';
        $generation = ($this->definitionCacheGeneration ?? 'default')
            . "\0" . $this->definitionCacheRevision;

        return 'imx.'
            . substr(hash('sha256', $this->alias), 0, 16)
            . '.' . substr(hash('sha256', $generation), 0, 16)
            . '.' . substr(hash('sha256', $definition . "\0" . $environment), 0, 16);
    }

    /** @internal */
    public function markResolved(string $id): void
    {
        $this->resolvedIds[$id] = true;
    }

    public function onResolved(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->hasHooks = true;
        $this->onResolvedHooks[$id][] = $hook;
    }

    public function onResolving(string $id, callable $hook): void
    {
        $this->checkIfLocked();
        $this->hasHooks = true;
        $this->onResolvingHooks[$id][] = $hook;
    }

    public function onScopeLeave(string $scope, callable $hook): void
    {
        $this->checkIfLocked();
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

    /**
     * Resets the current scope, removing all resolved resources that were
     * created under the current scope.
     *
     * This method is useful when you want to reset the state of the container
     * after a scope has been used (for example, after a request has been
     * processed).
     */
    public function resetScope(): void
    {
        $this->clearScopeResolvedEntries($this->currentScope);
        $this->scopeStack = [];
        $this->currentScope = 'root';
        $this->resolvedScoped = [];
        $this->scopeSeeds = [];
    }

    /** Rotate only InterMix's cache namespace; never clear a caller-owned pool. */
    public function rotateDefinitionCacheGeneration(): void
    {
        ++$this->definitionCacheRevision;
    }

    /**
     * Sets the alias of this definition repository.
     *
     * The alias is a unique identifier for this repository. It is used to
     * generate cache keys and to identify the repository when serializing
     * or unserializing the container.
     *
     * @param string $alias The alias to set.
     *
     * @throws ContainerException if the container is locked.
     */
    public function setAlias(string $alias): void
    {
        $this->checkIfLocked();
        if ($this->alias === $alias) {
            return;
        }
        $this->alias = $alias;
        $this->invalidateResolutionConfiguration();
    }

    /**
     * @param Closure $resolver Generated dispatcher accepting the container and service ID.
     * @param array<string, mixed> $ids Validated compiled identifiers and fingerprints.
     * @internal
     */
    public function setCompiledResolver(Closure $resolver, array $ids): void
    {
        $this->checkIfLocked();
        $this->compiledResolver = $resolver;
        $this->compiledResolverIds = $ids;
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

    /**
     * Sets the default method to be called when resolving a class.
     *
     * The selected name applies when class resources provide no explicit method.
     *
     * @param string|null $method The default method name, or null to unset.
     *
     * @throws ContainerException if the container is locked.
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

    /** Configure the optional definition cache without invalidating resolver state. */
    public function setDefinitionCache(
        CacheItemPoolInterface $cache,
        ?string $generation = null,
        bool $failOpen = true,
    ): void {
        $this->checkIfLocked();
        if ($generation === '') {
            throw new ContainerException('Definition cache generation cannot be empty.');
        }

        $this->definitionCache = $cache;
        $this->definitionCacheFailOpen = $failOpen;
        if ($generation !== null && $generation !== $this->definitionCacheGeneration) {
            $this->definitionCacheGeneration = $generation;
            $this->definitionCacheRevision = 0;
        }
    }

    /**
     * @param string $id The id of the definition to set the meta for.
     * @param array{lifetime?: LifetimeEnum, tags?: array<int, scalar|null>} $meta The meta data to set for the definition.
     *                                                                             - lifetime: The lifetime of the definition. Defaults to Lifetime::Singleton.
     *                                                                             - tags: An array of tags to associate with the definition. Defaults to an empty array.
     *
     * @throws ContainerException
     */
    public function setDefinitionMeta(string $id, array $meta): void
    {
        $this->checkIfLocked();
        $normalized = $meta + ['lifetime' => LifetimeEnum::Singleton, 'tags' => []];
        $normalizedTags = [];
        foreach ($normalized['tags'] as $tag) {
            $normalizedTags[] = (string) $tag;
        }
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
     * Override definition meta for a specific environment.
     *
     * Supported keys:
     *  - lifetime: LifetimeEnum
     *  - tags: array<int, string>
     *
     * @param array{lifetime?: LifetimeEnum, tags?: array<int, scalar|null>} $meta
     *
     * @throws ContainerException
     */
    public function setDefinitionMetaForEnv(
        string $env,
        string $id,
        array $meta,
    ): void {
        $this->checkIfLocked();
        $existing = $this->definitionMetaByEnv[$env][$id] ?? [];

        $normalized = [];
        if (array_key_exists('lifetime', $meta)) {
            $normalized['lifetime'] = $meta['lifetime'];
        }
        if (array_key_exists('tags', $meta)) {
            $tags = [];
            foreach ($meta['tags'] as $tag) {
                $tags[] = (string) $tag;
            }
            $normalized['tags'] = $tags;
        }

        if ($normalized === []) {
            return;
        }

        if ($existing === ($normalized + $existing)) {
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

    /**
     * Set the environment for this repository.
     *
     * The environment can be used to resolve environment-based interface mappings.
     * If the environment is set to a non-empty string, we will check if there is
     * a matching environment-based mapping for a given interface.
     *
     * @param string $env environment name
     *
     * @throws ContainerException if the container is locked
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
     * Sets a function reference for the given identifier.
     *
     * This method assigns a function definition to an identifier in the
     * repository. It ensures that the container is not locked before
     * making modifications.
     *
     * @param string $id The identifier for the function reference.
     * @param mixed $definition The definition of the function reference.
     *
     * @throws ContainerException if the container is locked.
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
     * Store a runtime resolution result without changing container definitions.
     *
     * @param string $id The ID of the resource.
     * @param mixed $value The resolved value of the resource.
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
     * Stores a resolved definition value by name.
     *
     * This method takes the name of a definition and a value, and stores the
     * value in the "resolvedDefinition" array with the definition name as the
     * key.
     *
     * @param string $defName The name of the definition to store.
     * @param mixed $value The value to store.
     */
    public function setResolvedDefinition(string $defName, mixed $value): void
    {
        $this->resolvedDefinition[$defName] = $value;
    }

    /**
     * Stores a resolved resource for a class-based resource.
     *
     * @param string $className The class name of the resource.
     */
    public function setResolvedResource(string $className, ClassResolution $data): void
    {
        $this->resolvedResource[$className] = $data;
    }

    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        $this->resolvedScoped[$scope][$id] = $value;
    }

    /**
     * Set the current scope for the container.
     *
     * The scope is used to determine the lifetime of definitions. For example, if the
     * scope is set to 'request', definitions will be resolved once per request.
     *
     * @param string $scope The scope to set. Defaults to 'root'.
     */
    public function setScope(string $scope): void
    {
        $this->currentScope = $scope;
    }

    public function shouldPersistDefinitionValue(mixed $value): bool
    {
        return $this->isSafeCachedDefinitionValue($value);
    }

    /**
     * @return DebugTracer The debug tracer associated with this repository.
     *
     * The debug tracer is used to track and log the execution flow and
     * interactions within the container, aiding in debugging and
     * tracing the service resolution process.
     */
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

    /**
     * Throw an exception if the container is locked and we try to set/modify values.
     *
     * @throws ContainerException
     */
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

        $safe = true;
        foreach ($value as $item) {
            $safe = $this->isSafeCachedDefinitionValue($item);
            if (!$safe) {
                break;
            }
        }

        return $safe;
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
