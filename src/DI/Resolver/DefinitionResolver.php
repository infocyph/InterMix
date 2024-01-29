<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;

class DefinitionResolver
{
    use Reflector;

    private ClassResolver $classResolver;
    private ParameterResolver $parameterResolver;
    private array $entriesResolving = [];

    /**
     * Constructor for the class.
     *
     * @param Repository $repository The repository object.
     */
    public function __construct(
        private Repository $repository
    ) {
    }

    /**
     * Sets the ClassResolver instance for the object.
     *
     * @param ClassResolver $classResolver The ClassResolver instance to set.
     * @param ParameterResolver $parameterResolver The ParameterResolver instance to set.
     * @return void
     */
    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver
    ): void {
        $this->classResolver = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }

    /**
     * Prepare the definition for a given name.
     *
     * @param string $name The name of the definition.
     * @return mixed The prepared definition.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(string $name): mixed
    {
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency detected while resolving definition '$name'");
        }
        $this->entriesResolving[$name] = true;

        try {
            $resolved = $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
        }

        return $resolved;
    }

    /**
     * Retrieves the value from the cache if it exists, otherwise resolves the value.
     *
     * @param string $name The name of the value to retrieve or resolve.
     * @return mixed The resolved value.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function getFromCacheOrResolve(string $name): mixed
    {
        if (!array_key_exists($name, $this->repository->resolvedDefinition)) {
            $this->repository->resolvedDefinition[$name] = match (true) {
                isset($this->repository->cacheAdapter) => $this->repository->cacheAdapter->get(
                    $this->repository->alias . '-' . base64_encode($name),
                    fn () => $this->resolveDefinition($name)
                ),
                default => $this->resolveDefinition($name)
            };
        }
        return $this->repository->resolvedDefinition[$name];
    }

    /**
     * Prepare the definition for a given name.
     *
     * @param string $name The name of the definition.
     * @return mixed The resolved definition.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveDefinition(string $name): mixed
    {
        $definition = $this->repository->functionReference[$name];

        return match (true) {
            $definition instanceof Closure => $definition(
                ...$this->parameterResolver->resolve(new ReflectionFunction($definition), [], 'constructor')
            ),

            is_array($definition) && class_exists($definition[0])
            => function () use ($definition) {
                $resolved = $this->classResolver->resolve(
                    ...$definition
                );
                return empty($definition[1]) ? $resolved['instance'] : $resolved['returned'];
            },

            is_string($definition) && class_exists($definition)
            => $this->classResolver->resolve($this->reflectedClass($definition))['instance'],

            default => $definition
        };
    }
}
