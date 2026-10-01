<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Build\StaticRuntimePlanner;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\AutowireDefinition;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\InputDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PreloadGenerator;
use Infocyph\InterMix\DI\Support\RuntimeFactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\DI\Support\ValueDefinition;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use ReflectionReference;

final class ContainerBuilder
{
    private const int METADATA_MAX_DEPTH = 64;

    private const int METADATA_MAX_VALUES = 100_000;

    private readonly ConfigurationContainer $configuration;

    /** @var array<string, true> */
    private array $cacheDefinitionIds = [];

    /** @var null|array{compiled: list<string>, skipped: array<string, string>, digest: string} */
    private ?array $compilationReport = null;

    /** @var array<string, true> */
    private array $dynamicServiceIds = [];

    private ?DefinitionGraph $graph = null;

    /** @var array<string, true> */
    private array $resolvedHookIds = [];

    /** @var array<string, true> */
    private array $resolvingHookIds = [];

    /** @var array<string, true> */
    private array $scopeLeaveHookScopes = [];

    public function __construct(string $namespace = 'intermix.default')
    {
        $this->configuration = new ConfigurationContainer($namespace);
    }

    public static function create(string $namespace = 'intermix.default'): self
    {
        return new self($namespace);
    }

    public function alias(string $id, string $target): self
    {
        $this->assertIdentifier($target, 'Alias target');
        $this->registerExplicit($id, new AliasDefinition($target));

        return $this;
    }

    /**
     * @param class-string $class
     * @param array<int|string, mixed> $arguments
     * @param array<int, string> $tags
     * @param array<string, mixed> $properties
     */
    public function autowire(
        string $id,
        string $class,
        array $arguments = [],
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
        array $properties = [],
    ): self {
        $this->registerExplicit(
            $id,
            new AutowireDefinition(
                $class,
                $this->snapshotMetadata($arguments, 'Autowire arguments'),
                $this->snapshotMetadata($properties, 'Autowire properties'),
            ),
            $lifetime,
            $tags,
        );

        return $this;
    }

    public function bindInterfaceForEnv(string $environment, string $interface, string $concrete): self
    {
        $this->assertMutable();
        $this->configuration->getRepository()->bindInterfaceForEnv($environment, $interface, $concrete);

        return $this;
    }

    public function build(): Container
    {
        $this->finalizeGraph();

        return ContainerAccess::fork($this->configuration);
    }

    public function cacheDefinition(string $id): self
    {
        $this->assertMutable();
        $this->assertIdentifier($id);
        $this->cacheDefinitionIds[$id] = true;
        $this->configuration->getRepository()->setDefinitionCacheEligible($id);

        return $this;
    }

    /** @return null|array{compiled: list<string>, skipped: array<string, string>, digest: string} */
    public function compilationReport(): ?array
    {
        return $this->compilationReport;
    }

    /** @return array{compiled: list<string>, skipped: array<string, string>, digest: string} */
    public function compile(string $path, bool $strict = false): array
    {
        $graph = $this->finalizeGraph();

        if ($strict) {
            $planned = new StaticRuntimePlanner()->plan($graph);
            if ($planned['skipped'] !== []) {
                $failures = [];
                foreach ($planned['skipped'] as $id => $reason) {
                    $failures[] = "{$id}: {$reason}";
                }

                throw new ContainerException(
                    "Strict static compilation rejected unsupported definitions:\n- "
                    . implode("\n- ", $failures),
                );
            }
        }

        $generated = new StaticRuntimeGenerator()->generate($graph, $path);
        $this->compilationReport = [
            'compiled' => $generated['compiled'],
            'skipped' => $generated['skipped'],
            'digest' => $generated['digest'],
        ];

        return $this->compilationReport;
    }

    public function definitionCache(
        CacheItemPoolInterface $pool,
        string $namespace,
        string $generation,
        bool $failOpen = true,
    ): self {
        $this->assertMutable();
        if ($namespace === '') {
            throw new ContainerException('Definition cache namespace cannot be empty.');
        }
        if ($generation === '') {
            throw new ContainerException('Definition cache generation cannot be empty.');
        }

        $this->configuration->getRepository()->setDefinitionCache(
            $pool,
            generation: $generation,
            failOpen: $failOpen,
            namespace: $namespace,
            explicitOnly: true,
        );

        return $this;
    }

    /**
     * Return the finalized immutable definition graph.
     *
     * @internal
     */
    public function definitionGraph(): DefinitionGraph
    {
        return $this->finalizeGraph();
    }

    public function enableDebugTracing(
        bool $enable = true,
        TraceLevelEnum $level = TraceLevelEnum::Node,
    ): self {
        $this->assertMutable();
        $this->configuration->getRepository()->tracer()->setLevel(
            $enable ? $level : TraceLevelEnum::Off,
        );

        return $this;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->assertMutable();
        $this->configuration->enableLazyLoading($lazy);

        return $this;
    }

    public function enableMethodAttributes(bool $enable = true): self
    {
        $this->assertMutable();
        $this->configuration->getRepository()->enableMethodAttribute($enable);

        return $this;
    }

    public function enablePropertyAttributes(bool $enable = true): self
    {
        $this->assertMutable();
        $this->configuration->getRepository()->enablePropertyAttribute($enable);

        return $this;
    }

    /** @return array<string, mixed> */
    public function exportGraph(?string $warmFromId = null, bool $clear = false): array
    {
        return ContainerAccess::graph($this->build(), $warmFromId, $clear);
    }

    /** @param array<int, string> $tags */
    public function factory(
        string $id,
        Closure|FactoryDefinition $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $definition = $factory instanceof Closure
            ? new RuntimeFactoryDefinition($factory)
            : $factory;
        $this->registerExplicit($id, $definition, $lifetime, $tags);

        return $this;
    }

    public function generatePreload(string $path): self
    {
        $this->finalizeGraph();
        new PreloadGenerator()->generate($this->configuration, $path);

        return $this;
    }

    public function import(ServiceProviderInterface $provider): self
    {
        $this->assertMutable();
        $provider->register($this);

        return $this;
    }

    public function input(string $id): self
    {
        $this->registerExplicit($id, new InputDefinition(), LifetimeEnum::Scoped);

        return $this;
    }

    public function onResolved(string $id, callable $callback): self
    {
        $this->assertMutable();
        $this->resolvedHookIds[$id] = true;
        $this->configuration->onResolved($id, $callback);

        return $this;
    }

    public function onResolving(string $id, callable $callback): self
    {
        $this->assertMutable();
        $this->resolvingHookIds[$id] = true;
        $this->configuration->onResolving($id, $callback);

        return $this;
    }

    public function onScopeLeave(string $scope, callable $callback): self
    {
        $this->assertMutable();
        $this->scopeLeaveHookScopes[$scope] = true;
        $this->configuration->onScopeLeave($scope, $callback);

        return $this;
    }

    public function production(string $path): ProductionContainer
    {
        $this->finalizeGraph();
        $fallback = $this->configuration->forkConfigurationRuntime(false);

        try {
            return new StaticRuntimeGenerator()->load($path, $fallback);
        } finally {
            $fallback->lock();
        }
    }

    public function productionPrevalidated(string $path, string $digest): ProductionContainer
    {
        $this->finalizeGraph();
        $fallback = $this->configuration->forkConfigurationRuntime(false);

        try {
            return new StaticRuntimeGenerator()->loadPrevalidated(
                $path,
                $digest,
                $fallback,
            );
        } finally {
            $fallback->lock();
        }
    }

    public function registerAttributeResolver(string $attributeFqcn, string $resolverFqcn): self
    {
        $this->assertMutable();
        $this->configuration->getRepository()->attributeRegistry()->register(
            $attributeFqcn,
            $resolverFqcn,
        );

        return $this;
    }

    /** @param array<int, string>|null $tags */
    public function setDefinitionMetaForEnv(
        string $environment,
        string $id,
        ?LifetimeEnum $lifetime = null,
        ?array $tags = null,
    ): self {
        $this->assertMutable();
        $this->assertIdentifier($id);

        $meta = [];
        if ($lifetime !== null) {
            $meta['lifetime'] = $lifetime;
        }
        if ($tags !== null) {
            $meta['tags'] = $tags;
        }

        $this->configuration->getRepository()->setDefinitionMetaForEnv($environment, $id, $meta);

        return $this;
    }

    public function setEnvironment(string $environment): self
    {
        $this->assertMutable();
        $this->configuration->setEnvironment($environment);

        return $this;
    }

    public function unbind(string $id): self
    {
        $this->assertMutable();
        $this->assertIdentifier($id);
        $this->configuration->unbind($id);
        unset($this->cacheDefinitionIds[$id], $this->dynamicServiceIds[$id]);

        return $this;
    }

    /** @return array<int, string> */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        $issues = [
            ...$this->configuration->validate(false, $resolveFactories),
            ...$this->validateBuilderState(),
        ];
        $issues = array_values(array_unique($issues));

        if ($strict && $issues !== []) {
            throw new ContainerException("Container validation failed:\n- " . implode("\n- ", $issues));
        }

        return $issues;
    }

    public function value(string $id, mixed $value): self
    {
        $this->registerExplicit($id, new ValueDefinition($value));

        return $this;
    }

    /** @return array{hits: int, written: int, skipped: int, failed: int} */
    public function warmDefinitionCache(): array
    {
        $this->finalizeGraph();

        return $this->configuration->definitions()->warmDefinitionCache();
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        $this->assertMutable();
        $this->assertIdentifier($consumer, 'Contextual consumer');

        return new ContextualBindingBuilder(
            $this,
            $this->configuration->getRepository(),
            $consumer,
        );
    }

    private function assertIdentifier(string $id, string $label = 'Service ID'): void
    {
        if ($id === '') {
            throw new ContainerException("{$label} must be a non-empty string.");
        }
    }

    private function assertMutable(): void
    {
        if ($this->graph instanceof DefinitionGraph) {
            throw new ContainerException(
                'ContainerBuilder is finalized; create a new builder to change configuration.',
            );
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function autowireArgumentIssues(
        string $id,
        AutowireDefinition $definition,
        ReflectionClass $reflection,
    ): array {
        $keys = array_keys($definition->arguments);
        $hasIntegerKeys = array_any($keys, static fn(int|string $key): bool => is_int($key));
        $hasStringKeys = array_any($keys, static fn(int|string $key): bool => is_string($key));

        if ($hasIntegerKeys && $hasStringKeys) {
            return ["Autowire definition '{$id}' mixes positional and named constructor arguments."];
        }

        if ($hasIntegerKeys) {
            return array_is_list($definition->arguments)
                ? []
                : ["Autowire definition '{$id}' positional constructor arguments must be a list."];
        }

        if (!$hasStringKeys) {
            return [];
        }

        $known = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $known[$parameter->getName()] = true;
        }

        $issues = [];
        foreach ($keys as $key) {
            if (is_string($key) && !isset($known[$key])) {
                $issues[] = "Autowire definition '{$id}' has unknown constructor argument '{$key}'.";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function autowireIssues(string $id, AutowireDefinition $definition): array
    {
        if (!class_exists($definition->class)) {
            return ["Autowire definition '{$id}' references missing class '{$definition->class}'."];
        }

        $reflection = new ReflectionClass($definition->class);
        if (!$reflection->isInstantiable()) {
            return ["Autowire definition '{$id}' class '{$definition->class}' is not instantiable."];
        }

        return [
            ...$this->autowireArgumentIssues($id, $definition, $reflection),
            ...$this->autowirePropertyIssues($id, $definition, $reflection),
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function autowirePropertyIssues(
        string $id,
        AutowireDefinition $definition,
        ReflectionClass $reflection,
    ): array {
        $issues = [];
        foreach ($definition->properties as $property => $_value) {
            if (!$reflection->hasProperty($property)) {
                $issues[] = "Autowire definition '{$id}' has unknown property '{$property}'.";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function cacheEligibilityIssues(): array
    {
        $issues = [];
        $repository = $this->configuration->getRepository();

        foreach (array_keys($this->cacheDefinitionIds) as $rawId) {
            $id = (string) $rawId;
            if (!$repository->hasFunctionReference($id)) {
                $issues[] = "Definition cache eligibility references unknown ID '{$id}'.";

                continue;
            }

            $definition = $repository->getFunctionDefinition($id);
            if (!$definition instanceof RuntimeFactoryDefinition
                && !$definition instanceof FactoryDefinition
            ) {
                $issues[] = "Definition '{$id}' is not an explicit factory and cannot use external definition caching.";

                continue;
            }

            if ($repository->getDefinitionLifetime($id) !== LifetimeEnum::Singleton) {
                $issues[] = "Definition '{$id}' must be Singleton to use external definition caching.";
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function captiveDependencyIssues(DefinitionGraph $graph): array
    {
        $plans = new StaticRuntimePlanner()->plan($graph)['plans'];
        $issues = [];
        foreach ($plans as $id => $plan) {
            if ($plan['lifetime'] !== LifetimeEnum::Singleton) {
                continue;
            }

            $pending = $plan['dependencies'];
            $seen = [];
            while (($dependency = array_pop($pending)) !== null) {
                if (isset($seen[$dependency])) {
                    continue;
                }
                $seen[$dependency] = true;
                $dependencyPlan = $plans[$dependency] ?? null;
                if (!is_array($dependencyPlan)) {
                    continue;
                }
                if ($dependencyPlan['lifetime'] === LifetimeEnum::Scoped) {
                    $issues[] = "Singleton '{$id}' depends on scoped entry '{$dependency}'.";

                    break;
                }
                array_push($pending, ...$dependencyPlan['dependencies']);
            }
        }

        return $issues;
    }

    private function finalizeGraph(): DefinitionGraph
    {
        if ($this->graph instanceof DefinitionGraph) {
            return $this->graph;
        }

        $issues = [
            ...$this->configuration->validate(false, false),
            ...$this->validateBuilderState(),
        ];
        $issues = array_values(array_unique($issues));
        if ($issues !== []) {
            throw new ContainerException("Container validation failed:\n- " . implode("\n- ", $issues));
        }

        $graph = DefinitionGraph::from(
            $this->configuration->getRepository(),
            array_keys($this->dynamicServiceIds),
            array_keys($this->resolvingHookIds),
            array_keys($this->resolvedHookIds),
            array_keys($this->scopeLeaveHookScopes),
        );

        $captiveIssues = $this->captiveDependencyIssues($graph);
        if ($captiveIssues !== []) {
            throw new ContainerException(
                "Container validation failed:\n- " . implode("\n- ", $captiveIssues),
            );
        }

        $this->configuration->lock();
        $this->graph = $graph;

        return $graph;
    }

    /** @return list<string> */
    private function graphIssues(): array
    {
        $repository = $this->configuration->getRepository();
        $definitions = $repository->getFunctionReference();
        $issues = [];

        foreach ($definitions as $rawId => $definition) {
            $id = (string) $rawId;
            if ($definition instanceof AliasDefinition) {
                if (!$repository->hasFunctionReference($definition->target)) {
                    $issues[] = "Alias '{$id}' targets undeclared service '{$definition->target}'.";

                    continue;
                }

                $seen = [];
                $current = $id;
                while (($alias = $definitions[$current] ?? null) instanceof AliasDefinition) {
                    if (isset($seen[$current])) {
                        $issues[] = "Alias '{$id}' participates in a cycle.";

                        break;
                    }
                    $seen[$current] = true;
                    $current = $alias->target;
                }
            }

            if ($definition instanceof AutowireDefinition) {
                array_push($issues, ...$this->autowireIssues($id, $definition));
            }
        }

        return $issues;
    }

    /**
     * @param array<int, string> $tags
     */
    private function registerExplicit(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $this->assertMutable();
        $this->assertIdentifier($id);
        $repository = $this->configuration->getRepository();
        if ($repository->hasFunctionReference($id)) {
            throw new ContainerException(
                "Definition '{$id}' is already registered; call unbind() before replacing it.",
            );
        }

        $repository->setDefinition($id, $definition, $lifetime, $tags);
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $values
     * @return array<TKey, mixed>
     */
    private function snapshotMetadata(array $values, string $label): array
    {
        $activeReferences = [];
        $visited = 0;

        return $this->snapshotMetadataLevel($values, $label, 0, $activeReferences, $visited);
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $values
     * @param array<string, true> $activeReferences
     * @return array<TKey, mixed>
     */
    private function snapshotMetadataLevel(
        array $values,
        string $label,
        int $depth,
        array &$activeReferences,
        int &$visited,
    ): array {
        if ($depth > self::METADATA_MAX_DEPTH) {
            throw new ContainerException("{$label} exceed the maximum nesting depth.");
        }

        $snapshot = [];
        foreach ($values as $key => $value) {
            if (++$visited > self::METADATA_MAX_VALUES) {
                throw new ContainerException("{$label} exceed the maximum value budget.");
            }
            if (!is_array($value)) {
                $snapshot[$key] = $value;

                continue;
            }

            $reference = ReflectionReference::fromArrayElement($values, $key);
            $referenceId = $reference?->getId();
            if ($referenceId !== null && isset($activeReferences[$referenceId])) {
                throw new ContainerException("{$label} contain a cyclic array reference.");
            }
            if ($referenceId !== null) {
                $activeReferences[$referenceId] = true;
            }

            $snapshot[$key] = $this->snapshotMetadataLevel(
                $value,
                $label,
                $depth + 1,
                $activeReferences,
                $visited,
            );

            if ($referenceId !== null) {
                unset($activeReferences[$referenceId]);
            }
        }

        return $snapshot;
    }

    /** @return list<string> */
    private function validateBuilderState(): array
    {
        return [
            ...$this->graphIssues(),
            ...$this->cacheEligibilityIssues(),
        ];
    }
}
