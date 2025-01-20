<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class ClassResolver
{
    /**
     * Tracks classes currently being resolved to detect circular dependencies.
     *
     * @var array<string,bool>
     */
    private array $entriesResolving = [];

    /**
     * Constructor for the class.
     */
    public function __construct(
        private Repository $repository,
        private readonly ParameterResolver $parameterResolver,
        private readonly PropertyResolver $propertyResolver,
        private readonly DefinitionResolver $definitionResolver
    ) {
    }

    /**
     * Resolves the given Infuse object and returns the result.
     *
     * @return mixed The resolved value (or null if function/class not found).
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveInfuse(Infuse $infuse): mixed
    {
        $type = $infuse->getParameterData('type');

        // 1) If we have a definition (functionReference) for $type
        if ($this->repository->hasFunctionReference($type)) {
            return $this->definitionResolver->resolve($type);
        }

        // 2) If it is a global function
        if (function_exists($type)) {
            // e.g. call user function with Infuse data
            $reflectionFn = ReflectionResource::getFunctionReflection($type);

            return $type(...$this->parameterResolver->resolve(
                $reflectionFn,
                (array) $infuse->getParameterData('data'),
                'constructor'
            ));
        }

        // 3) Otherwise return null if there's nothing to resolve
        return null;
    }

    /**
     * Resolves a given class and returns the resolved resources.
     *
     * @param  mixed  $supplied  Extra data for constructor or interface resolution
     * @param  string|bool|null  $callMethod  The method to be called (or false if none)
     * @param  bool  $make  Whether to create a new instance ignoring previous
     * @return array The resolved resource: ['instance' => object, 'returned' => mixed|null, ...]
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool|null $callMethod = null,
        bool $make = false
    ): array {
        // Possibly replace if $class is interface
        $class = $this->getConcreteClassForInterface($class, $supplied);
        $className = $class->getName();

        if ($make) {
            return $this->resolveMake($class, $className, $callMethod);
        }

        $this->resolveClassResources($class, $className, $callMethod);

        return $this->repository->getResolvedResource()[$className];
    }

    /**
     * Internal method that resolves a "make" request to produce a fresh instance.
     */
    private function resolveMake(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): array {
        // Temporarily store the existing resolved resource
        $existing = $this->repository->getResolvedResource()[$className] ?? [];

        // Rebuild the instance from scratch
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);

        // Now store the newly built resource, but revert $repository->resolvedResource[$className]
        // to the original to avoid overwriting the "singleton" or shared resource.
        $newlyBuilt = $this->repository->getResolvedResource()[$className];
        $this->repository->setResolvedResource($className, $existing);

        return $newlyBuilt;
    }

    /**
     * Resolves the resources for a class if not already resolved.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): void {
        // Check for circular dependency
        if (isset($this->entriesResolving[$className])) {
            throw new ContainerException(
                "Circular dependency detected while resolving class '$className'"
            );
        }
        $this->entriesResolving[$className] = true;

        try {
            // If instance not built yet, resolve constructor
            $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];
            if (! isset($resolvedResource['instance'])) {
                $this->resolveConstructor($class);
            }

            // If property not resolved, do property injection
            if (! isset($resolvedResource['property'])) {
                $this->propertyResolver->resolve($class);
            }

            // Optionally call a method
            $this->resolveMethod($class, $callMethod);
        } finally {
            unset($this->entriesResolving[$className]);
        }
    }

    /**
     * If ReflectionClass is an interface, attempt to reflect the user-supplied class.
     *
     * @throws ContainerException|ReflectionException
     */
    private function getConcreteClassForInterface(
        ReflectionClass $class,
        mixed $supplied
    ): ReflectionClass {
        if (! $class->isInterface()) {
            return $class;
        }

        $interfaceName = $class->getName();
        if (! $supplied || ! class_exists($supplied)) {
            throw new ContainerException(
                "Resolution failed ($supplied) for interface $interfaceName"
            );
        }

        // e.g. $supplied is "ConcreteClass"
        $concreteReflect = ReflectionResource::getClassReflection($supplied);
        if (! $concreteReflect->implementsInterface($interfaceName)) {
            throw new ContainerException(
                "$supplied doesn't implement $interfaceName"
            );
        }

        return $concreteReflect;
    }

    /**
     * Resolves the constructor of a given class (if any) and stores the instance.
     *
     * @throws ContainerException If the class is not instantiable
     * @throws ReflectionException|InvalidArgumentException
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();

        if (! $class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            // No constructor => create instance with no arguments
            $instance = $class->newInstanceWithoutConstructor();
        } else {
            // Resolve constructor params
            $classResource = $this->repository->getClassResource();
            $suppliedParams = $classResource[$className]['constructor']['params'] ?? [];

            $args = $this->parameterResolver->resolve($constructor, $suppliedParams, 'constructor');
            $instance = $class->newInstanceArgs($args);
        }

        // Store in repository->resolvedResource[$className]['instance']
        $resolved = $this->repository->getResolvedResource()[$className] ?? [];
        $resolved['instance'] = $instance;
        $this->repository->setResolvedResource($className, $resolved);
    }

    /**
     * Resolves the method call for the class if needed, storing the return in ['returned'].
     *
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveMethod(ReflectionClass $class, string|bool|null $callMethod = null): void
    {
        $className = $class->getName();

        // Initialize 'returned' to null in repository
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];
        $resolvedResource['returned'] = null;

        // If $callMethod === false, we skip calling any method
        if ($callMethod === false) {
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        // If user didn't supply a method, check classResource or defaultMethod
        $method = $callMethod
            ?: ($this->repository->getClassResource()[$className]['method']['on'] ?? null)
                ?: ($class->getConstant('callOn') ?: $this->repository->getDefaultMethod());

        // If no method found, try __invoke if available, else skip
        if (! $method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }

        if (! $method || ! $class->hasMethod($method)) {
            // No method to call
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        // Use ReflectionResource to get a ReflectionMethod (caching)
        $reflectionMethod = ReflectionResource::getCallableReflection([$className, $method]);

        // Resolve method params (if any)
        $classResource = $this->repository->getClassResource();
        $suppliedParams = $classResource[$className]['method']['params'] ?? [];

        $args = $this->parameterResolver->resolve($reflectionMethod, $suppliedParams, 'method');

        // Call the method
        $instance = $resolvedResource['instance'];
        $returned = $reflectionMethod->invokeArgs($instance, $args);

        // Store in repository
        $resolvedResource['returned'] = $returned;
        $this->repository->setResolvedResource($className, $resolvedResource);
    }
}
