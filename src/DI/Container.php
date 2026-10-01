<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\InvocationManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Resolver\ConcurrentRepository;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\CompiledResolverGenerator;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PendingFactoryBinding;
use Infocyph\InterMix\DI\Support\TaggedPipeline;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;
use ReflectionException;
use Throwable;

class Container implements RuntimeContainerInterface
{
    protected ?DefinitionManager $definitionManager = null;

    protected InvocationManager $invocationManager;

    protected ?OptionsManager $optionsManager = null;

    protected ?RegistrationManager $registrationManager = null;

    protected Repository $repository;

    protected Closure|CompiledCall|InjectedCall|GenericCall $resolver;

    /** @var null|array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>} */
    private ?array $compilationReport = null;

    /** @var class-string<InjectedCall|GenericCall> */
    private string $resolverClass = InjectedCall::class;

    public function __construct(protected readonly string $instanceAlias = 'intermix.default')
    {
        $this->repository = new ConcurrentRepository($this, $this->instanceAlias);
        $this->resolver = $this->resolverFactory();
        $this->invocationManager = new InvocationManager($this->repository, $this);
    }

    public function captureScopeContext(): ScopeContext
    {
        return $this->scopeContextRepository()->captureScopeContext();
    }

    /** @return array<int|string, mixed> */
    public function debug(string $id): array
    {
        $tracer = $this->repository->tracer();
        $previousLevel = $tracer->level();
        $previousCaptureLocation = $tracer->isCaptureLocationEnabled();

        try {
            $tracer->setCaptureLocation(true);
            $tracer->setLevel(TraceLevelEnum::Verbose);
            $this->get($id);
        } catch (Throwable) {
        } finally {
            $tracer->setCaptureLocation($previousCaptureLocation);
            $tracer->setLevel($previousLevel);
        }

        return $tracer->toArray();
    }

    /** @throws \Exception|\Psr\Cache\InvalidArgumentException */
    public function get(string $id): mixed
    {
        try {
            return $this->invocationManager->get($id);
        } catch (NotFoundException $exception) {
            if (!$this->has($id)) {
                throw $exception;
            }

            throw new ContainerException(
                "Declared entry '$id' could not be resolved: {$exception->getMessage()}",
                previous: $exception,
            );
        } catch (ContainerException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            if ($this->repository->isOnMissingFailure($throwable)) {
                throw $throwable;
            }

            throw new ContainerException(
                "Error retrieving entry '$id': {$throwable->getMessage()}",
                previous: $throwable,
            );
        }
    }

    /** @phpstan-impure */
    public function has(string $id): bool
    {
        return $this->invocationManager->has($id);
    }

    /** @param array<int|string, mixed> $arguments */
    public function invoke(callable $callable, array $arguments = []): mixed
    {
        return $this->invocationManager->invoke($callable, $arguments);
    }

    /** @param array<int|string, mixed> $arguments */
    public function make(string $class, array $arguments = []): object
    {
        return $this->invocationManager->make($class, $arguments);
    }

    public function pipeline(string $tag): TaggedPipeline
    {
        return new TaggedPipeline($this, $tag);
    }

    public function resetCurrentExecutionScope(): void
    {
        $this->scopeContextRepository()->resetCurrentExecutionScope();
    }

    /** @return iterable<string, mixed> */
    public function tagged(string $tag): iterable
    {
        $repository = $this->scopeContextRepository();
        $scopeContext = $repository->getScope() === 'root'
            ? null
            : $repository->captureScopeContext();
        $ids = $this->repository->getIdsByTag($tag);

        return (function () use ($ids, $repository, $scopeContext): iterable {
            foreach ($ids as $id) {
                if ($scopeContext instanceof ScopeContext) {
                    $repository->assertCurrentScopeContext($scopeContext);
                }

                yield $id => $this->get($id);
            }
        })();
    }

    public function tracer(): DebugTracer
    {
        return $this->repository->tracer();
    }

    /** @param array<string, mixed> $instances */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        if ($instances !== []) {
            $this->validateScopeSeeds($instances);
        }
        $this->enterScope($scope, $instances);

        try {
            $result = $callback($this);
        } catch (Throwable $throwable) {
            $this->leaveScopeAfter($throwable);

            throw $throwable;
        }

        $this->leaveScope();

        return $result;
    }

    public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed
    {
        $repository = $this->scopeContextRepository();
        $repository->attachScopeContext($scopeContext);

        try {
            return $callback($this);
        } finally {
            $repository->detachScopeContextIfAttached($scopeContext);
        }
    }

    protected function alias(string $id, string $target, LifetimeEnum $lifetime = LifetimeEnum::Singleton): self
    {
        $this->definitions()->bind($id, $target, $lifetime);

        return $this;
    }

    protected function attributeRegistry(): AttributeRegistry
    {
        return $this->repository->attributeRegistry();
    }

    /** @param array<int, string> $tags */
    protected function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->definitions()->bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    protected function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        return $this->bind($id, new DirectFactory($factory, $this), $lifetime, $tags);
    }

    /** @return null|array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>} */
    protected function compilationReport(): ?array
    {
        return $this->compilationReport;
    }

    /** @throws ContainerException|ReflectionException */
    protected function compileTo(string $path, bool $load = false): self
    {
        $compiled = new CompiledResolverGenerator()->generate($this, $path);
        $this->compilationReport = $compiled['report'];
        if ($load) {
            $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
            $this->activateCompiledResolver();
        }

        return $this;
    }

    protected function copyConfigurationInto(self $runtime, bool $locked): void
    {
        $this->repository->copyConfigurationTo($runtime->repository);
        $runtime->resolverClass = $this->resolverClass;
        $runtime->resolver = $runtime->resolverFactory();

        if ($locked) {
            $runtime->repository->lock();
        }
    }

    protected function definitions(): DefinitionManager
    {
        $host = $this->configurationHost();

        return $this->definitionManager ??= new DefinitionManager($this->repository, $host);
    }

    protected function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);

        return $this;
    }

    /** @param array<string, mixed> $instances */
    protected function enterScope(string $scope, array $instances = []): self
    {
        $this->repository->enterScope($scope, $instances);

        return $this;
    }

    /** @return array<string, mixed> */
    protected function exportGraph(?string $warmFromId = null, bool $clear = false): array
    {
        if ($warmFromId !== null) {
            $this->get($warmFromId);
        }

        return $this->repository->tracer()->dependencyGraph($clear);
    }

    protected function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return new PendingFactoryBinding($this->configurationHost(), $id, $factory);
    }

    /** @return array<string, mixed> */
    protected function findByTag(string $tag): array
    {
        $matches = [];
        foreach ($this->repository->getIdsByTag($tag) as $id) {
            $matches[$id] = $this->get($id);
        }

        return $matches;
    }

    /** @return iterable<string, callable(): mixed> */
    protected function findByTagLazy(string $tag): iterable
    {
        foreach ($this->repository->getIdsByTag($tag) as $id) {
            yield $id => fn() => $this->get($id);
        }
    }

    /**
     * Create an isolated runtime from this container's finalized configuration.
     *
     * @internal
     */
    protected function forkRuntime(bool $locked = true): self
    {
        $runtime = new self($this->instanceAlias);
        $this->copyConfigurationInto($runtime, $locked);

        return $runtime;
    }

    /** @internal */
    protected function getCurrentResolver(): CompiledCall|InjectedCall|GenericCall
    {
        if ($this->resolver instanceof Closure) {
            $resolved = ($this->resolver)();
            if (!$resolved instanceof CompiledCall
                && !$resolved instanceof InjectedCall
                && !$resolved instanceof GenericCall
            ) {
                throw new ContainerException(
                    sprintf(
                        'Invalid resolver instance. Expected %s, %s, or %s.',
                        CompiledCall::class,
                        InjectedCall::class,
                        GenericCall::class,
                    ),
                );
            }
            $this->resolver = $resolved;
        }

        return $this->resolver;
    }

    /** @internal */
    protected function getRepository(): Repository
    {
        return $this->repository;
    }

    protected function invocation(): InvocationManager
    {
        return $this->invocationManager;
    }

    protected function isResolved(string $id): bool
    {
        return $this->repository->isResolved($id);
    }

    protected function leaveScope(): self
    {
        $this->repository->leaveScope();

        return $this;
    }

    protected function lock(): self
    {
        $this->repository->lock();

        return $this;
    }

    protected function onMissing(callable $callback): self
    {
        $this->repository->onMissing($callback);

        return $this;
    }

    protected function onResolved(string $id, callable $callback): self
    {
        $this->repository->onResolved($id, $callback);

        return $this;
    }

    protected function onResolving(string $id, callable $callback): self
    {
        $this->repository->onResolving($id, $callback);

        return $this;
    }

    protected function onScopeLeave(string $scope, callable $callback): self
    {
        $this->repository->onScopeLeave($scope, $callback);

        return $this;
    }

    protected function options(): OptionsManager
    {
        $host = $this->configurationHost();

        return $this->optionsManager ??= new OptionsManager($this->repository, $host);
    }

    protected function registration(): RegistrationManager
    {
        $host = $this->configurationHost();

        return $this->registrationManager ??= new RegistrationManager($this->repository, $host);
    }

    /** @param array<int, string> $tags */
    protected function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Scoped, $tags);

        return $this;
    }

    protected function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);

        return $this;
    }

    /**
     * @param class-string<InjectedCall|GenericCall> $resolverClass
     * @internal
     */
    protected function setResolverClass(string $resolverClass): void
    {
        $this->repository->assertMutable();
        if ($this->resolverClass === $resolverClass) {
            return;
        }
        $this->repository->invalidateResolutionConfiguration();
        $this->resolverClass = $resolverClass;
        $this->resolver = $this->resolverFactory();
    }

    /** @param array<int, string> $tags */
    protected function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Singleton, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    protected function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Transient, $tags);

        return $this;
    }

    protected function unbind(string $id): self
    {
        $this->definitions()->unbind($id);

        return $this;
    }

    protected function useCompiled(string $path): self
    {
        $compiled = new CompiledResolverGenerator()->load($this, $path);
        $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
        $this->activateCompiledResolver();

        return $this;
    }

    protected function usePrevalidated(string $path, string $fingerprint): self
    {
        $compiled = new CompiledResolverGenerator()->loadPrevalidated($path, $fingerprint);
        $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
        $this->activateCompiledResolver();

        return $this;
    }

    /** @return array<int, string> */
    protected function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        $issues = $this->validateDefinitionTargets();
        if ($resolveFactories) {
            $issues = array_merge($issues, $this->validateResolvableDefinitions());
        }
        if ($strict && $issues !== []) {
            throw new ContainerException("Container validation failed:\n- " . implode("\n- ", $issues));
        }

        return $issues;
    }

    /** @param array<string, mixed> $instances */
    protected function validateScopeSeeds(array $instances): void
    {
        foreach ($instances as $id => $_instance) {
            if (!$this->repository->isScopeSeedAllowed((string) $id)) {
                throw new ContainerException(
                    "Scope seed '$id' must identify a declared scoped entry or input.",
                );
            }
        }
    }

    protected function value(string $id, mixed $value): self
    {
        $this->definitions()->bind($id, $value, LifetimeEnum::Singleton);

        return $this;
    }

    protected function when(string $consumer): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $this->repository, $consumer);
    }

    private function activateCompiledResolver(): void
    {
        if ($this->resolverClass !== GenericCall::class) {
            $this->resolver = new CompiledCall($this->repository);
        }
    }

    private function configurationHost(): ConfigurationContainer
    {
        if (!$this instanceof ConfigurationContainer) {
            throw new ContainerException(
                'Runtime containers do not expose mutable configuration managers.',
            );
        }

        return $this;
    }

    private function leaveScopeAfter(?Throwable $workFailure): void
    {
        try {
            $this->leaveScope();
        } catch (ScopeCleanupException $cleanupFailure) {
            if ($workFailure === null) {
                throw $cleanupFailure;
            }

            throw new ScopeCleanupException(
                $cleanupFailure->cleanupFailures,
                $cleanupFailure->cleanupFailureCount,
                $workFailure,
            );
        }
    }

    private function resolverFactory(): Closure
    {
        return function (): CompiledCall|InjectedCall|GenericCall {
            if ($this->resolverClass === GenericCall::class) {
                return new GenericCall($this->repository);
            }
            if ($this->repository->hasCompiledResolvers()) {
                return new CompiledCall($this->repository);
            }

            return new InjectedCall($this->repository);
        };
    }

    private function scopeContextRepository(): ConcurrentRepository
    {
        if (!$this->repository instanceof ConcurrentRepository) {
            throw new ContainerException(
                'Scope-context propagation requires a concurrent InterMix repository.',
            );
        }

        return $this->repository;
    }

    /** @return array<int, string> */
    private function validateDefinitionTargets(): array
    {
        $issues = [];
        foreach ($this->repository->getFunctionReference() as $id => $definition) {
            if ($id === '') {
                $issues[] = 'Invalid definition id detected.';

                continue;
            }
            if (is_string($definition)
                && !class_exists($definition)
                && !function_exists($definition)
                && !$this->repository->hasFunctionReference($definition)
            ) {
                $issues[] = "Definition '{$id}' points to unknown string target '{$definition}'.";
            }
            if (is_array($definition)
                && isset($definition[0])
                && is_string($definition[0])
                && !class_exists($definition[0])
            ) {
                $issues[] = "Definition '{$id}' references missing class '{$definition[0]}'.";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function validateResolvableDefinitions(): array
    {
        $issues = [];
        foreach ($this->repository->getFunctionReference() as $id => $_) {
            try {
                $this->get($id);
            } catch (Throwable $e) {
                $issues[] = "Resolution failure for '{$id}': {$e->getMessage()}";
            }
        }

        return $issues;
    }
}
