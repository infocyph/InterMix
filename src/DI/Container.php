<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use ArrayAccess;
use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
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
use Infocyph\InterMix\DI\Support\ContainerProxy;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PendingFactoryBinding;
use Infocyph\InterMix\DI\Support\TaggedPipeline;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionException;
use Throwable;

/** @implements ArrayAccess<string, mixed> */
final class Container implements ContainerInterface, ArrayAccess
{
    use ContainerProxy;

    public const string DEFAULT_ALIAS = 'intermix.default';

    public const string DI_ALIAS = 'intermix.di';

    public const string DIRECT_ALIAS = 'intermix.direct';

    private const int CALLABLE_DESCRIPTOR_CACHE_LIMIT = 512;

    /** @var array<string, self> */
    protected static array $instances = [];

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

    public function __construct(private readonly string $instanceAlias = self::DEFAULT_ALIAS)
    {
        $this->repository = new ConcurrentRepository($this, $this->instanceAlias);
        $this->resolver = $this->resolverFactory();
        $this->invocationManager = new InvocationManager($this->repository, $this);
    }

    public static function instance(string $instanceAlias = self::DEFAULT_ALIAS): self
    {
        return self::$instances[$instanceAlias] ??= new self($instanceAlias);
    }

    public function alias(string $id, string $target, LifetimeEnum $lifetime = LifetimeEnum::Singleton): self
    {
        $this->definitions()->bind($id, $target, $lifetime);

        return $this;
    }

    public function attributeRegistry(): AttributeRegistry
    {
        return $this->repository->attributeRegistry();
    }

    /** @param array<int, string> $tags */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $this->definitions()->bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        return $this->bind($id, new DirectFactory($factory, $this), $lifetime, $tags);
    }

    /** @throws ContainerException|ReflectionException|\Psr\Cache\InvalidArgumentException */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        return $this->invocationManager->call($classOrClosure, $method);
    }

    /** @return null|array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>} */
    public function compilationReport(): ?array
    {
        return $this->compilationReport;
    }

    /** @throws ContainerException|ReflectionException */
    public function compileTo(string $path, bool $load = false): self
    {
        $compiled = new CompiledResolverGenerator()->generate($this, $path);
        $this->compilationReport = $compiled['report'];
        if ($load) {
            $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
            $this->activateCompiledResolver();
        }

        return $this;
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

    public function definitions(): DefinitionManager
    {
        return $this->definitionManager ??= new DefinitionManager($this->repository, $this);
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);

        return $this;
    }

    /** @param array<string, mixed> $instances */
    public function enterScope(string $scope, array $instances = []): self
    {
        $this->repository->enterScope($scope, $instances);

        return $this;
    }

    /** @return array<string, mixed> */
    public function exportGraph(?string $warmFromId = null, bool $clear = false): array
    {
        if ($warmFromId !== null) {
            $this->get($warmFromId);
        }

        return $this->repository->tracer()->dependencyGraph($clear);
    }

    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return new PendingFactoryBinding($this, $id, $factory);
    }

    /** @return array<string, mixed> */
    public function findByTag(string $tag): array
    {
        $matches = [];
        foreach ($this->repository->getIdsByTag($tag) as $id) {
            $matches[$id] = $this->get($id);
        }

        return $matches;
    }

    /** @return iterable<string, callable(): mixed> */
    public function findByTagLazy(string $tag): iterable
    {
        foreach ($this->repository->getIdsByTag($tag) as $id) {
            yield $id => fn() => $this->get($id);
        }
    }

    /** @throws \Exception|\Psr\Cache\InvalidArgumentException */
    public function get(string $id): mixed
    {
        try {
            return $this->invocationManager->get($id);
        } catch (NotFoundException|ContainerException $exception) {
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

    /** @internal */
    public function getCurrentResolver(): CompiledCall|InjectedCall|GenericCall
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
    public function getRepository(): Repository
    {
        return $this->repository;
    }

    /** @throws ContainerException|ReflectionException|\Psr\Cache\InvalidArgumentException */
    public function getReturn(string $id): mixed
    {
        return $this->invocationManager->getReturn($id);
    }

    /** @phpstan-impure */
    public function has(string $id): bool
    {
        return $this->invocationManager->has($id);
    }

    public function invocation(): InvocationManager
    {
        return $this->invocationManager;
    }

    public function isResolved(string $id): bool
    {
        return $this->repository->isResolved($id);
    }

    public function leaveScope(): self
    {
        $this->repository->leaveScope();

        return $this;
    }

    public function lock(): self
    {
        $this->repository->lock();

        return $this;
    }

    /** @throws ContainerException|ReflectionException */
    public function make(string $class, string|bool $method = false): mixed
    {
        return $this->invocationManager->make($class, $method);
    }

    public function onMissing(callable $callback): self
    {
        $this->repository->onMissing($callback);

        return $this;
    }

    public function onResolved(string $id, callable $callback): self
    {
        $this->repository->onResolved($id, $callback);

        return $this;
    }

    public function onResolving(string $id, callable $callback): self
    {
        $this->repository->onResolving($id, $callback);

        return $this;
    }

    public function onScopeLeave(string $scope, callable $callback): self
    {
        $this->repository->onScopeLeave($scope, $callback);

        return $this;
    }

    public function options(): OptionsManager
    {
        return $this->optionsManager ??= new OptionsManager($this->repository, $this);
    }

    /**
     * @param string|array<array-key, mixed>|Closure|callable $spec
     * @return array{kind:'closure',closure:callable}|array{kind:'class',class:string}|array{kind:'method',class:string,method:string}|array{kind:'function',function:string}
     */
    public function parseCallable(string|array|Closure|callable $spec): array
    {
        if ($spec === '' || $spec === []) {
            throw new InvalidArgumentException('No argument provided!');
        }

        /** @var array<string, array{kind:'class',class:string}|array{kind:'method',class:string,method:string}|array{kind:'function',function:string}> $descriptorCache */
        static $descriptorCache = [];

        $cacheKey = match (true) {
            is_string($spec) => "s\0$spec",
            is_array($spec)
                && count($spec) === 2
                && isset($spec[0], $spec[1])
                && is_string($spec[0])
                && is_string($spec[1]) => "m\0{$spec[0]}\0{$spec[1]}",
            default => null,
        };
        if ($cacheKey !== null && isset($descriptorCache[$cacheKey])) {
            return $descriptorCache[$cacheKey];
        }

        $descriptor = $this->parseCallableDescriptor($spec);
        if ($cacheKey !== null && $descriptor['kind'] !== 'closure') {
            if (count($descriptorCache) >= self::CALLABLE_DESCRIPTOR_CACHE_LIMIT) {
                unset($descriptorCache[array_key_first($descriptorCache)]);
            }
            $descriptorCache[$cacheKey] = $descriptor;
        }

        return $descriptor;
    }

    public function pipeline(string $tag): TaggedPipeline
    {
        return new TaggedPipeline($this, $tag);
    }

    public function registration(): RegistrationManager
    {
        return $this->registrationManager ??= new RegistrationManager($this->repository, $this);
    }

    /**
     * @param string|array<array-key, mixed>|Closure|callable|null $spec
     * @param array<int|string, mixed> $parameters
     */
    public function resolveNow(
        string|Closure|callable|array|null $spec,
        array $parameters = [],
    ): mixed {
        if ($spec === null) {
            return $this;
        }

        $desc = $this->parseCallable($spec);

        return match ($desc['kind']) {
            'closure', 'function' => $this->resolveRegisteredClosureCallable($desc, $parameters),
            'class', 'method' => $this->resolveRegisteredCallable($desc, $parameters),
        };
    }

    /** @param array<int, string> $tags */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Scoped, $tags);

        return $this;
    }

    public function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);

        return $this;
    }

    /**
     * @param class-string<InjectedCall|GenericCall> $resolverClass
     * @internal
     */
    public function setResolverClass(string $resolverClass): void
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
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Singleton, $tags);

        return $this;
    }

    /** @return iterable<string, callable(): mixed> */
    public function tagged(string $tag): iterable
    {
        return $this->findByTagLazy($tag);
    }

    public function tracer(): DebugTracer
    {
        return $this->repository->tracer();
    }

    /** @param array<int, string> $tags */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        $this->definitions()->bind($id, $definition ?? $id, LifetimeEnum::Transient, $tags);

        return $this;
    }

    public function unbind(string $id): self
    {
        $this->definitions()->unbind($id);

        return $this;
    }

    public function unset(): void
    {
        if ((self::$instances[$this->instanceAlias] ?? null) === $this) {
            unset(self::$instances[$this->instanceAlias]);
        }
    }

    public function useCompiled(string $path): self
    {
        $compiled = new CompiledResolverGenerator()->load($this, $path);
        $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
        $this->activateCompiledResolver();

        return $this;
    }

    public function usePrevalidated(string $path, string $fingerprint): self
    {
        $compiled = new CompiledResolverGenerator()->loadPrevalidated($path, $fingerprint);
        $this->repository->setCompiledResolver($compiled['resolver'], $compiled['ids']);
        $this->activateCompiledResolver();

        return $this;
    }

    /** @return array<int, string> */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
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

    public function value(string $id, mixed $value): self
    {
        $this->definitions()->bind($id, $value, LifetimeEnum::Singleton);

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $consumer);
    }

    /** @param array<string, mixed> $instances */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);

        try {
            return $callback($this);
        } finally {
            $this->leaveScope();
        }
    }

    private function activateCompiledResolver(): void
    {
        if ($this->resolverClass !== GenericCall::class) {
            $this->resolver = new CompiledCall($this->repository);
        }
    }

    /**
     * @param array<array-key, mixed> $spec
     * @return array{kind:'method',class:string,method:string}
     */
    private function parseArrayMethodCallable(array $spec): array
    {
        [$class, $method] = array_values($spec);
        if (!is_string($class) || !is_string($method)) {
            throw new ContainerException(
                'Invalid callable array. Expected [class, method] with non-empty strings.',
            );
        }

        return $this->parseClassMethodParts($class, $method, '[class, method]');
    }

    /**
     * @param string|array<array-key, mixed>|Closure|callable $spec
     * @return array{kind:'closure',closure:callable}|array{kind:'class',class:string}|array{kind:'method',class:string,method:string}|array{kind:'function',function:string}
     */
    private function parseCallableDescriptor(string|array|Closure|callable $spec): array
    {
        return match (true) {
            $spec instanceof Closure => ['kind' => 'closure', 'closure' => $spec],
            is_array($spec) && count($spec) === 2 && is_string($spec[0]) && is_string($spec[1])
                => $this->parseArrayMethodCallable($spec),
            is_string($spec) => match (true) {
                str_contains($spec, '@') => $this->parseClassMethodString($spec, '@'),
                str_contains($spec, '::') => $this->parseClassMethodString($spec, '::'),
                class_exists($spec) => ['kind' => 'class', 'class' => $spec],
                function_exists($spec) => ['kind' => 'function', 'function' => $spec],
                default => throw new ContainerException(
                    sprintf(
                        "Unknown callable string '%s'. Expected 'class@method', 'class::method', class, or function.",
                        $spec,
                    ),
                ),
            },
            is_callable($spec) => ['kind' => 'closure', 'closure' => $spec],
            default => throw new ContainerException(
                sprintf(
                    "Unknown callable spec for '%s'. Expected closure/callable, 'class@method', 'class::method', [class,method], class, or function.",
                    gettype($spec),
                ),
            ),
        };
    }

    /** @return array{kind:'method',class:string,method:string} */
    private function parseClassMethodParts(
        string $class,
        string $method,
        string $spec,
        ?string $separator = null,
    ): array {
        $class = trim($class);
        $method = trim($method);
        if ($class === '' || $method === '') {
            if ($separator === null) {
                throw new ContainerException(
                    'Invalid callable array. Expected [class, method] with non-empty strings.',
                );
            }

            throw new ContainerException(
                sprintf(
                    'Invalid callable string "%s". Expected non-empty class and method around "%s".',
                    $spec,
                    $separator,
                ),
            );
        }
        if (!class_exists($class)) {
            throw new ContainerException(
                $separator === null
                    ? sprintf('Invalid callable array. Class "%s" does not exist.', $class)
                    : sprintf('Invalid callable string "%s". Class "%s" does not exist.', $spec, $class),
            );
        }
        if (!method_exists($class, $method)) {
            throw new ContainerException(
                $separator === null
                    ? sprintf('Invalid callable array. Method "%s::%s" does not exist.', $class, $method)
                    : sprintf(
                        'Invalid callable string "%s". Method "%s::%s" does not exist.',
                        $spec,
                        $class,
                        $method,
                    ),
            );
        }

        return ['kind' => 'method', 'class' => $class, 'method' => $method];
    }

    /**
     * @param non-empty-string $separator
     * @return array{kind:'method',class:string,method:string}
     */
    private function parseClassMethodString(string $spec, string $separator): array
    {
        [$class, $method] = explode($separator, $spec, 2);

        return $this->parseClassMethodParts($class, $method, $spec, $separator);
    }

    /**
     * @param array{kind:'class',class:string}|array{kind:'method',class:string,method:string} $desc
     * @param array<int|string, mixed> $parameters
     */
    private function resolveRegisteredCallable(array $desc, array $parameters): mixed
    {
        $resolver = $this->getCurrentResolver();
        if ($desc['kind'] === 'method') {
            return $resolver->classSettler(
                $desc['class'],
                $desc['method'],
                true,
                methodParameters: $parameters,
            )->returned;
        }

        return $resolver->classSettler(
            $desc['class'],
            false,
            true,
            constructorParameters: $parameters,
        )->instance;
    }

    /**
     * @param array{kind:'closure',closure:callable}|array{kind:'function',function:string} $desc
     * @param array<int|string, mixed> $parameters
     */
    private function resolveRegisteredClosureCallable(array $desc, array $parameters): mixed
    {
        if ($desc['kind'] === 'function') {
            $callback = $desc['function'];
            if (!function_exists($callback)) {
                throw new ContainerException("Unknown callable function '$callback'.");
            }
        } else {
            $callback = $desc['closure'];
        }

        return $this->getCurrentResolver()->closureSettler($callback, $parameters);
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
