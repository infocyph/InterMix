<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;

class DefinitionResolver
{
    private array $entriesResolving = [];
    private ClassResolver $classResolver;
    private ParameterResolver $parameterResolver;

    public function __construct(
        private readonly Repository $repository
    ) {
        //
    }

    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver
    ): void {
        $this->classResolver    = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }

    /**
     * Resolve a definition by name (id).
     */
    public function resolve(string $name): mixed
    {
        // debug?
        if ($this->repository->isDebug()) {
            // e.g. error_log("DefinitionResolver: resolving '$name'");
        }

        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency for definition '$name'.");
        }
        $this->entriesResolving[$name] = true;
        try {
            return $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
        }
    }

    private function getFromCacheOrResolve(string $name): mixed
    {
        $resolvedDefs = $this->repository->getResolvedDefinition();
        if (!isset($resolvedDefs[$name])) {
            $resolverCallback = fn () => $this->resolveDefinition($name);
            $cacheAdapter = $this->repository->getCacheAdapter();
            if ($cacheAdapter) {
                $cacheKey = $this->repository->makeCacheKey('def' . base64_encode($name));
                $value = $cacheAdapter->get($cacheKey, $resolverCallback);
                $this->repository->setResolvedDefinition($name, $value);
            } else {
                $value = $resolverCallback();
                $this->repository->setResolvedDefinition($name, $value);
            }
        }
        return $this->repository->getResolvedDefinition()[$name];
    }

    private function resolveDefinition(string $name): mixed
    {
        $definition = $this->repository->getFunctionReference()[$name] ?? null;
        switch (true) {
            case $definition instanceof Closure:
                // reflect closure
                $reflectionFn = ReflectionResource::getFunctionReflection($definition);
                $args = $this->parameterResolver->resolve($reflectionFn, [], 'constructor');
                return $definition(...$args);

            case is_array($definition) && isset($definition[0]) && class_exists($definition[0]):
                return $this->resolveArrayDefinition($definition);

            case is_string($definition) && class_exists($definition):
                // environment-based interface => already in ClassResolver
                $refClass = ReflectionResource::getClassReflection($definition);
                $res = $this->classResolver->resolve($refClass);
                return $res['instance'];

            default:
                return $definition;
        }
    }

    private function resolveArrayDefinition(array $definition): mixed
    {
        // e.g. [$className, $methodOrBool]
        $resolved = $this->classResolver->resolve(...$definition);
        return !empty($definition[1]) ? $resolved['returned'] : $resolved['instance'];
    }
}
