<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class ClassResolver
{
    use Reflector;

    /**
     * Constructor for the class.
     *
     * @param Repository $repository The repository object.
     * @param ParameterResolver $parameterResolver The parameter resolver object.
     * @param PropertyResolver $propertyResolver The property resolver object.
     */
    public function __construct(
        private Repository $repository,
        private ParameterResolver $parameterResolver,
        private PropertyResolver $propertyResolver
    ) {
    }

    /**
     * Resolves the given Infuse object and returns the result.
     *
     * @param Infuse $infuse The Infuse object to be resolved.
     * @return mixed The resolved value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveInfuse(Infuse $infuse): mixed
    {
        $type = $infuse->getNonMethodData('type');

        if (array_key_exists($type, $this->repository->functionReference)) {
            return $this->parameterResolver->getResolvedDefinition($type);
        }

        if (function_exists($type)) {
            return $type(
                ...
                $this->parameterResolver->resolve(
                    new ReflectionFunction($type),
                    (array)$infuse->getNonMethodData('data'),
                    'constructor'
                )
            );
        }

        return null;
    }

    /**
     * Resolves a given class and returns the resolved resources.
     *
     * @param ReflectionClass $class The class to be resolved.
     * @param mixed $supplied The supplied value.
     * @param string|bool $callMethod The method to be called.
     * @param bool $make Whether to create a new instance of the class.
     * @return array The resolved resources.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool $callMethod = null,
        bool $make = false
    ): array {
        $class = $this->getClass($class, $supplied);
        $className = $class->getName();
        if ($make) {
            return $this->resolveMake($class, $className, $callMethod);
        }
        $this->resolveClassResources($class, $className, $callMethod);
        return $this->repository->resolvedResource[$className];
    }

    /**
     * Rebuild the resources for the make operation for a given class name.
     *
     * @param ReflectionClass $class The reflection class object.
     * @param string $className The name of the class.
     * @param string|bool|null $callMethod The method to be called.
     * @return array The resolved resource.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveMake(ReflectionClass $class, string $className, string|bool|null $callMethod): array
    {
        $existing = $this->repository->resolvedResource[$className] ?? [];
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);
        $resolved = $this->repository->resolvedResource[$className];
        $this->repository->resolvedResource[$className] = $existing;
        return $resolved;
    }

    /**
     * Resolves the class resources.
     *
     * @param ReflectionClass $class The reflection class.
     * @param string $className The class name.
     * @param string|bool|null $callMethod The method to call.
     * @return void
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): void {
        if (!isset($this->repository->resolvedResource[$className]['instance'])) {
            $this->resolveConstructor($class);
        }

        if (!isset($this->repository->resolvedResource[$className]['property'])) {
            $this->propertyResolver->resolve($class);
        }

        $this->resolveMethod($class, $callMethod);
    }

    /**
     * Retrieves the ReflectionClass object for a given class or interface.
     *
     * @param ReflectionClass $class The class or interface for which to retrieve the ReflectionClass object.
     * @param mixed $supplied The name of the class to use for resolution, if the given class is an interface.
     * @return ReflectionClass The ReflectionClass object for the given class or interface.
     * @throws ContainerException|ReflectionException
     */
    private function getClass(ReflectionClass $class, mixed $supplied): ReflectionClass
    {
        if ($class->isInterface()) {
            $className = $class->getName();
            if (!$supplied || !class_exists($supplied)) {
                throw new ContainerException("Resolution failed $supplied for interface $className");
            }
            [$interface, $className] = [$className, $supplied];
            $class = $this->reflectedClass($className);
            if (!$class->implementsInterface($interface)) {
                throw new ContainerException("$className doesn't implement $interface");
            }
        }
        return $class;
    }

    /**
     * Resolves the constructor of a given class and initializes an instance of it.
     *
     * @param ReflectionClass $class The reflection class object.
     * @return void
     * @throws ContainerException If the class is not instantiable.
     * @throws ReflectionException|InvalidArgumentException
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (!$class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $this->repository->resolvedResource[$className]['instance'] = $constructor === null ?
            $class->newInstanceWithoutConstructor() :
            $class->newInstanceArgs(
                $this->parameterResolver->resolve(
                    $constructor,
                    $this->repository->classResource[$className]['constructor']['params'] ?? [],
                    'constructor'
                )
            );
    }

    /**
     * Resolves the method to be called based on the given class and call method.
     *
     * @param ReflectionClass $class The reflection class object.
     * @param string|bool $callMethod The name of the method to be called or false if no method is specified.
     * @return void
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveMethod(ReflectionClass $class, string|bool $callMethod = null): void
    {
        $className = $class->getName();

        $this->repository->resolvedResource[$className]['returned'] = null;
        if ($callMethod === false) {
            return;
        }

        $method = $callMethod
            ?: $this->repository->classResource[$className]['method']['on']
                ??= $class->getConstant('callOn')
                ?: $this->repository->defaultMethod;

        $method = match (true) {
            $method && $class->hasMethod($method) => $method,
            $class->hasMethod('__invoke') => '__invoke',
            default => false
        };

        if (!$method) {
            return;
        }

        $reflectedMethod = new ReflectionMethod($className, $method);
        $this->repository->resolvedResource[$className]['returned'] = $reflectedMethod->invokeArgs(
            $this->repository->resolvedResource[$className]['instance'],
            $this->parameterResolver->resolve(
                $reflectedMethod,
                $this->repository->classResource[$className]['method']['params'] ?? [],
                'method'
            )
        );
    }
}
