<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
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
 * Delegates advanced logic to sub-managers.
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
     * Shared repository for definitions, resolved instances, etc.
     */
    protected Repository $repository;

    /**
     * Current resolver class name (e.g. InjectedCall::class or GenericCall::class)
     *
     * @var class-string
     */
    protected string $resolver;

    /**
     * Sub-Managers
     */
    protected DefinitionManager $definitionManager;

    protected RegistrationManager $registrationManager;

    protected OptionsManager $optionsManager;

    protected InvocationManager $invocationManager;

    /**
     * Container constructor
     */
    public function __construct(
        private readonly string $instanceAlias = 'default'
    ) {
        // Store instance in static registry
        self::$instances[$this->instanceAlias] ??= $this;

        // Initialize repository
        $this->repository = new Repository();
        // By default, map ContainerInterface::class => $this
        $this->repository->functionReference = [ContainerInterface::class => $this];
        $this->repository->alias = $this->instanceAlias;

        // Default resolver
        $this->resolver = InjectedCall::class;

        // Create manager objects
        $this->definitionManager = new DefinitionManager($this->repository, $this);
        $this->registrationManager = new RegistrationManager($this->repository, $this);
        $this->optionsManager = new OptionsManager($this->repository, $this);
        $this->invocationManager = new InvocationManager($this->repository, $this);
    }

    /**
     * Retrieve (or create) a container instance for a given alias
     */
    public static function instance(string $instanceAlias = 'default'): static
    {
        return self::$instances[$instanceAlias] ??= new static($instanceAlias);
    }

    /**
     * Removes this instance from the registry
     */
    public function unset(): void
    {
        unset(self::$instances[$this->instanceAlias]);
    }

    /**
     * Lock the container from future modifications
     */
    public function lock(): self
    {
        $this->repository->isLocked = true;

        return $this;
    }

    /*-------------------------------------------------------------------------
     |  (A) PSR-11 interface methods: "get" + "has".                          |
     |  The rest of the container features are delegated to managers.        |
     *------------------------------------------------------------------------*/

    /**
     * PSR-11 standard: Retrieve an entry by its identifier
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
     * PSR-11 standard: Check if the container can return an entry for this identifier
     */
    public function has(string $id): bool
    {
        try {
            return $this->invocationManager->has($id);
        } catch (Exception) {
            return false;
        }
    }

    /*-------------------------------------------------------------------------
     |  (B) Getters for sub-managers (all advanced usage goes through them).  |
     *------------------------------------------------------------------------*/

    public function getDefinitionManager(): DefinitionManager
    {
        return $this->definitionManager;
    }

    public function getRegistrationManager(): RegistrationManager
    {
        return $this->registrationManager;
    }

    public function getOptionsManager(): OptionsManager
    {
        return $this->optionsManager;
    }

    public function getInvocationManager(): InvocationManager
    {
        return $this->invocationManager;
    }

    /*-------------------------------------------------------------------------
     |  (C) Helpers so managers can discover the current resolver.            |
     *------------------------------------------------------------------------*/

    public function getRepositoryResolverClass(): string
    {
        return $this->resolver;
    }

    public function setResolverClass(string $resolverClass): void
    {
        $this->resolver = $resolverClass;
    }

    /*-------------------------------------------------------------------------
     |  (D) Utility: split() + wrapException() are still in the container.    |
     *------------------------------------------------------------------------*/

    /**
     * Splits class@method / class::method / [class, method] into [class, method].
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        if (empty($classAndMethod)) {
            throw new InvalidArgumentException('No argument provided!');
        }

        $isString = is_string($classAndMethod);

        // If a Closure or a string that is class_exists or is_callable
        $callableFormation = match (true) {
            $classAndMethod instanceof Closure
            || ($isString && (class_exists($classAndMethod) || is_callable($classAndMethod))) => [$classAndMethod, null],

            is_array($classAndMethod) && class_exists($classAndMethod[0]) => [$classAndMethod[0], $classAndMethod[1] ?? null],

            default => null
        };

        if ($callableFormation) {
            return $callableFormation;
        }

        // If we have Class@method or Class::method
        if ($isString) {
            return match (true) {
                str_contains($classAndMethod, '@') => explode('@', $classAndMethod, 2),
                str_contains($classAndMethod, '::') => explode('::', $classAndMethod, 2),
                default => throw new ContainerException(
                    'Unknown Class & Method format. Use [class, method], class@method, or class::method'
                )
            };
        }

        throw new ContainerException(
            'Invalid callable/class format (closure, array, string, or callable expected).'
        );
    }

    /**
     * Wraps exceptions with a more meaningful error message
     */
    private function wrapException(Exception $exception, string $id): Exception
    {
        return match (true) {
            $exception instanceof NotFoundException => new NotFoundException("No entry found for '$id'"),
            default => new ContainerException("Error retrieving entry '$id': ".$exception->getMessage())
        };
    }
}
