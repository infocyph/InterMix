<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Build\StaticRuntimePlanner;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\AutowireDefinition;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\InputDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\RuntimeFactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\DI\Support\ValueDefinition;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;

final class ContainerBuilder
{
    private readonly Container $configuration;

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

    public function __construct(?Container $configuration = null)
    {
        $this->configuration = $configuration ?? new Container();
    }

    public static function create(string $alias = Container::DEFAULT_ALIAS): self
    {
        return new self(new Container($alias));
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
            new AutowireDefinition($class, $arguments, $properties),
            $lifetime,
            $tags,
        );

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->assertMutable();
        $this->configuration->bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->assertMutable();
        $this->configuration->bindFactory($id, $factory, $lifetime, $tags);

        return $this;
    }

    public function build(): Container
    {
        $this->finalizeGraph();

        return $this->configuration->forkRuntime();
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
     * Transitional 10.x configuration access. The returned manager becomes
     * immutable with the builder at finalization and is removed by P3.
     */
    public function definitions(): DefinitionManager
    {
        return $this->configuration->definitions();
    }

    /**
     * Transitional 10.x configuration access. The returned container is locked
     * at finalization and the escape hatch is removed by P3.
     */
    public function development(): Container
    {
        return $this->configuration;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->assertMutable();
        $this->configuration->enableLazyLoading($lazy);

        return $this;
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

    public function import(ServiceProviderInterface $provider): self
    {
        $this->assertMutable();
        $provider->register($this->configuration);

        return $this;
    }

    public function input(string $id): self
    {
        $this->registerExplicit($id, new InputDefinition(), LifetimeEnum::Scoped);

        return $this;
    }

    public function onMissing(callable $callback): self
    {
        $this->assertMutable();
        $this->configuration->onMissing($callback);

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

    /** Transitional 10.x configuration access; removed by P3. */
    public function options(): OptionsManager
    {
        return $this->configuration->options();
    }

    public function production(string $path): ProductionContainer
    {
        $this->finalizeGraph();

        return new StaticRuntimeGenerator()->load($path, $this->configuration->forkRuntime());
    }

    public function productionPrevalidated(string $path, string $digest): ProductionContainer
    {
        $this->finalizeGraph();

        return new StaticRuntimeGenerator()->loadPrevalidated(
            $path,
            $digest,
            $this->configuration->forkRuntime(),
        );
    }

    /** Transitional 10.x configuration access; removed by P3. */
    public function registration(): RegistrationManager
    {
        return $this->configuration->registration();
    }

    /** @param array<int, string> $tags */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->assertMutable();
        $this->configuration->scoped($id, $definition, $tags);

        return $this;
    }

    public function setEnvironment(string $environment): self
    {
        $this->assertMutable();
        $this->configuration->setEnvironment($environment);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->assertMutable();
        $this->configuration->singleton($id, $definition, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->assertMutable();
        $this->configuration->transient($id, $definition, $tags);

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

    public function when(string $consumer): ContextualBindingBuilder
    {
        $this->assertMutable();

        return $this->configuration->when($consumer);
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

    private function autowireIssues(string $id, AutowireDefinition $definition): array
    {
        if (!class_exists($definition->class)) {
            return ["Autowire definition '{$id}' references missing class '{$definition->class}'."];
        }

        $reflection = new ReflectionClass($definition->class);
        if (!$reflection->isInstantiable()) {
            return ["Autowire definition '{$id}' class '{$definition->class}' is not instantiable."];
        }

        $issues = [];
        $keys = array_keys($definition->arguments);
        $hasIntegerKeys = array_any($keys, static fn(int|string $key): bool => is_int($key));
        $hasStringKeys = array_any($keys, static fn(int|string $key): bool => is_string($key));
        if ($hasIntegerKeys && $hasStringKeys) {
            $issues[] = "Autowire definition '{$id}' mixes positional and named constructor arguments.";
        } elseif ($hasIntegerKeys && !array_is_list($definition->arguments)) {
            $issues[] = "Autowire definition '{$id}' positional constructor arguments must be a list.";
        } elseif ($hasStringKeys) {
            $constructor = $reflection->getConstructor();
            $known = [];
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $known[$parameter->getName()] = true;
            }
            foreach ($keys as $key) {
                if (is_string($key) && !isset($known[$key])) {
                    $issues[] = "Autowire definition '{$id}' has unknown constructor argument '{$key}'.";
                }
            }
        }

        foreach ($definition->properties as $property => $_value) {
            if (!$reflection->hasProperty($property)) {
                $issues[] = "Autowire definition '{$id}' has unknown property '{$property}'.";
            }
        }

        return $issues;
    }

    private function cacheEligibilityIssues(): array
    {
        $issues = [];
        $repository = $this->configuration->getRepository();

        foreach (array_keys($this->cacheDefinitionIds) as $id) {
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

        $this->configuration->lock();
        $this->graph = $graph;

        return $graph;
    }

    /** @return array<int, string> */
    private function graphIssues(): array
    {
        $repository = $this->configuration->getRepository();
        $definitions = $repository->getFunctionReference();
        $issues = [];

        foreach ($definitions as $id => $definition) {
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

    /** @return array<int, string> */
    private function validateBuilderState(): array
    {
        return [
            ...$this->graphIssues(),
            ...$this->cacheEligibilityIssues(),
        ];
    }
}
