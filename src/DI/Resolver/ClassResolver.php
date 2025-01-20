<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionMethod;

class ClassResolver
{
    private array $entriesResolving = [];

    public function __construct(
        private Repository $repository,
        private readonly ParameterResolver $parameterResolver,
        private readonly PropertyResolver $propertyResolver,
        private readonly DefinitionResolver $definitionResolver
    ) {
    }

    /**
     * If you have an Infuse attribute referencing a function or a class definition, we handle it.
     */
    public function resolveInfuse(Infuse $infuse): mixed
    {
        // 1) Extract the "type" (class name, function name, definition ID, etc.)
        $typeData = $infuse->getParameterData();
        // e.g. ['type' => 'SomeClassOrFunction', 'data' => [...]]

        $type = $typeData['type'] ?? null;
        $data = $typeData['data'] ?? [];  // extra data (array) if present

        // If no type, nothing to resolve
        if (! $type) {
            return null;
        }

        // 2) If $type is in functionReference => let definitionResolver handle it
        if ($this->repository->hasFunctionReference($type)) {
            return $this->definitionResolver->resolve($type);
        }

        // 3) If $type is a global function name
        if (function_exists($type)) {
            // Reflect the function to figure out parameters
            $reflectionFn = new \ReflectionFunction($type);
            // Use parameterResolver to handle injection or data
            $args = $this->parameterResolver->resolve($reflectionFn, (array) $data, 'constructor');

            // Call the function with resolved arguments
            return $type(...$args);
        }

        // 4) If $type is a class or interface => do a reflection-based resolution
        if (class_exists($type) || interface_exists($type)) {
            // (Optional) environment-based override if it's an interface
            if (interface_exists($type)) {
                $envConcrete = $this->repository->getEnvConcrete($type);
                if ($envConcrete && class_exists($envConcrete)) {
                    $type = $envConcrete;
                }
            }

            // Reflect & resolve using ClassResolver
            $reflection = ReflectionResource::getClassReflection($type);
            $resolved = $this->resolve($reflection);

            // $resolved is typically an array with ['instance'=>..., 'returned'=>...]
            return $resolved['instance'] ?? $resolved;
        }

        // 5) Otherwise, we have no way to resolve it
        return null;
    }

    /**
     * Resolves a given class, returning ['instance'=>..., 'returned'=>...].
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool|null $callMethod = null,
        bool $make = false
    ): array {
        // Possibly environment-based interface => check if $class->isInterface(), then environment override
        $class = $this->getConcreteClassForInterface($class, $supplied);
        $className = $class->getName();

        // If debug
        if ($this->repository->isDebug()) {
            // e.g. error_log("ClassResolver: resolving $className, make=$make");
        }

        return $make
            ? $this->resolveMake($class, $className, $callMethod)
            : $this->resolveClassResources($class, $className, $callMethod);
    }

    private function resolveMake(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): array {
        $existing = $this->repository->getResolvedResource()[$className] ?? [];

        // build fresh
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);

        $newlyBuilt = $this->repository->getResolvedResource()[$className] ?? [];
        // revert the old
        $this->repository->setResolvedResource($className, $existing);

        return $newlyBuilt;
    }

    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): array {
        if (isset($this->entriesResolving[$className])) {
            throw new ContainerException("Circular dependency on $className");
        }
        $this->entriesResolving[$className] = true;

        try {
            $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];

            if (! isset($resolvedResource['instance'])) {
                $this->resolveConstructor($class);
            }
            if (! isset($resolvedResource['property'])) {
                $this->propertyResolver->resolve($class);
            }
            $this->resolveMethod($class, $callMethod);

            return $this->repository->getResolvedResource()[$className] ?? [];
        } finally {
            unset($this->entriesResolving[$className]);
        }
    }

    private function getConcreteClassForInterface(
        ReflectionClass $class,
        mixed $supplied
    ): ReflectionClass {
        if (! $class->isInterface()) {
            return $class;
        }
        $className = $class->getName();
        // environment override
        $envConcrete = $this->repository->getEnvConcrete($className);
        if ($envConcrete) {
            $class = new ReflectionClass($envConcrete);
            if (! $class->implementsInterface($className)) {
                throw new ContainerException("$envConcrete doesn't implement $className");
            }

            return $class;
        }
        // fallback to $supplied
        if (! $supplied || ! class_exists($supplied)) {
            throw new ContainerException("Resolution failed ($supplied) for interface $className");
        }
        $reflect = new ReflectionClass($supplied);
        if (! $reflect->implementsInterface($className)) {
            throw new ContainerException("$supplied doesn't implement $className");
        }

        return $reflect;
    }

    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (! $class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];

        if ($constructor === null) {
            $resolvedResource['instance'] = $class->newInstanceWithoutConstructor();
        } else {
            $classRes = $this->repository->getClassResource();
            $params = $classRes[$className]['constructor']['params'] ?? [];
            $args = $this->parameterResolver->resolve($constructor, $params, 'constructor');
            $resolvedResource['instance'] = $class->newInstanceArgs($args);
        }
        $this->repository->setResolvedResource($className, $resolvedResource);
    }

    private function resolveMethod(
        ReflectionClass $class,
        string|bool|null $callMethod
    ): void {
        $className = $class->getName();
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];
        $resolvedResource['returned'] = null;

        if ($callMethod === false) {
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        $method = $callMethod
            ?: ($this->repository->getClassResource()[$className]['method']['on'] ?? null)
                ?: ($class->getConstant('callOn') ?: $this->repository->getDefaultMethod());

        if (! $method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }
        if (! $method || ! $class->hasMethod($method)) {
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        $refMethod = new ReflectionMethod($className, $method);
        $classRes = $this->repository->getClassResource();
        $params = $classRes[$className]['method']['params'] ?? [];
        $args = $this->parameterResolver->resolve($refMethod, $params, 'method');

        $returned = $refMethod->invokeArgs($resolvedResource['instance'], $args);
        $resolvedResource['returned'] = $returned;
        $this->repository->setResolvedResource($className, $resolvedResource);
    }
}
