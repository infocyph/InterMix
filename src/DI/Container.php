<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\InvocationManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Main Container class (implements PSR-11).
 * Incorporates:
 *   - environment-based logic (through Repository)
 *   - debug toggles
 *   - optional lazy loading
 *   - concurrency safety (via ReflectionResource, if enabled)
 */
class Container implements ContainerInterface
{
    /**
     * Registry of named container instances
     *
     * @var array<string, static>
     */
    protected static array $instances = [];

    /**
     * Shared repository
     */
    protected Repository $repository;

    /**
     * Resolver class name (InjectedCall::class or GenericCall::class or custom).
     *
     * @var class-string
     */
    protected string $resolver;

    /**
     * Sub-managers
     */
    protected DefinitionManager $definitionManager;

    protected RegistrationManager $registrationManager;

    protected OptionsManager $optionsManager;

    protected InvocationManager $invocationManager;

    public function __construct(private readonly string $instanceAlias = 'default')
    {
        // Store in static registry
        self::$instances[$this->instanceAlias] ??= $this;

        // Create the repository, default alias, initial states
        $this->repository = new Repository();
        $this->repository->setAlias($this->instanceAlias);

        // By default, map ContainerInterface::class => $this (like your original)
        $this->repository->setFunctionReference(
            ContainerInterface::class,
            $this
        );

        // Default resolver => "injected" style
        $this->resolver = \Infocyph\InterMix\DI\Invoker\InjectedCall::class;

        // Create manager objects
        $this->definitionManager = new DefinitionManager($this->repository, $this);
        $this->registrationManager = new RegistrationManager($this->repository, $this);
        $this->optionsManager = new OptionsManager($this->repository, $this);
        $this->invocationManager = new InvocationManager($this->repository, $this);
    }

    /**
     * Retrieve or create a container instance by alias.
     */
    public static function instance(string $instanceAlias = 'default'): static
    {
        return self::$instances[$instanceAlias] ??= new static($instanceAlias);
    }

    /**
     * PSR-11: Remove container instance from registry.
     */
    public function unset(): void
    {
        unset(self::$instances[$this->instanceAlias]);
    }

    /**
     * Lock the container from future modifications.
     */
    public function lock(): self
    {
        // Let the repository handle the lock
        $this->repository->lock();

        return $this;
    }

    /**
     * PSR-11: get(id)
     */
    public function get(string $id): mixed
    {
        try {
            return $this->invocationManager->get($id);
        } catch (Exception $exception) {
            throw $this->wrapException($exception, $id);
        }
    }

    /**
     * PSR-11: has(id)
     */
    public function has(string $id): bool
    {
        try {
            return $this->invocationManager->has($id);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Retrieve the definition manager.
     */
    public function definitions(): DefinitionManager
    {
        return $this->definitionManager;
    }

    /**
     * Retrieve the registration manager.
     */
    public function registration(): RegistrationManager
    {
        return $this->registrationManager;
    }

    /**
     * Retrieve the options manager.
     */
    public function options(): OptionsManager
    {
        return $this->optionsManager;
    }

    /**
     * Retrieve the invocation manager.
     */
    public function invocation(): InvocationManager
    {
        return $this->invocationManager;
    }

    /**
     * Public accessor for the repository’s $resolver
     */
    public function getRepositoryResolverClass(): string
    {
        return $this->resolver;
    }

    /**
     * Called by OptionsManager to switch between InjectedCall & GenericCall, etc.
     */
    public function setResolverClass(string $resolverClass): void
    {
        $this->resolver = $resolverClass;
    }

    /*-------------------------------------------------------------------------
     |  For convenience, you can still provide old facade methods if desired  |
     |  e.g. addDefinitions(), bind(), call(), make(), getReturn()            |
     *------------------------------------------------------------------------*/

    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        return $this->invocationManager->call($classOrClosure, $method);
    }

    public function make(string $class, string|bool $method = false): mixed
    {
        return $this->invocationManager->make($class, $method);
    }

    public function getReturn(string $id): mixed
    {
        return $this->invocationManager->getReturn($id);
    }

    /*-------------------------------------------------------------------------
     |  Environment/Debug/Lazy convenience wrappers if you prefer            |
     *------------------------------------------------------------------------*/

    public function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);

        return $this;
    }

    public function enableDebug(bool $enabled = true): self
    {
        $this->repository->setDebug($enabled);

        return $this;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);

        return $this;
    }

    /**
     * Splits class@method / class::method / [class, method] / closure or callable
     * into [class, method].
     *
     * If there's no method (e.g. just a class or a closure), we return [that, null].
     *
     * @param  string|array|Closure|callable  $classAndMethod
     * @return array{0:mixed,1:?string} [classOrCallable, methodOrNull]
     *
     * @throws InvalidArgumentException
     * @throws ContainerException
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        if (empty($classAndMethod)) {
            throw new InvalidArgumentException('No argument provided!');
        }

        // (1) If closure or a string that is a class_exists or function_exists,
        //     or a general is_callable, we return [that, null].
        //     This handles e.g. closures or "ClassName" with no method or a global function name.
        if ($classAndMethod instanceof Closure ||
            (is_string($classAndMethod) && (class_exists($classAndMethod) || function_exists($classAndMethod))) ||
            is_callable($classAndMethod)
        ) {
            return [$classAndMethod, null];
        }

        // (2) If it's an array with 2 elements => [class, method]
        if (is_array($classAndMethod) && count($classAndMethod) === 2) {
            return [$classAndMethod[0], $classAndMethod[1]];
        }

        // (3) If it's a string with "@" => "Class@method"
        if (is_string($classAndMethod) && str_contains($classAndMethod, '@')) {
            return explode('@', $classAndMethod, 2);
        }

        // (4) If it's a string with "::" => "Class::method"
        if (is_string($classAndMethod) && str_contains($classAndMethod, '::')) {
            return explode('::', $classAndMethod, 2);
        }

        throw new ContainerException(
            sprintf(
                "Unknown Class & Method format for '%s'. Expected closure/callable, 'class@method', 'class::method', or [class,method].",
                is_string($classAndMethod) ? $classAndMethod : gettype($classAndMethod)
            )
        );
    }

    /*-------------------------------------------------------------------------
     |  Utility: wrapException                                               |
     *------------------------------------------------------------------------*/
    private function wrapException(Exception $exception, string $id): Exception
    {
        return match (true) {
            $exception instanceof NotFoundException => new NotFoundException("No entry found for '$id'"),
            default => new ContainerException("Error retrieving entry '$id': ".$exception->getMessage()),
        };
    }
}
