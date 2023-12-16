<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

class ParameterResolver
{
    use Reflector;

    private ClassResolver $classResolver;
    private stdClass $stdClass;
    private array $entriesResolving = [];

    /**
     * Constructs a new instance of the class.
     *
     * @param Repository $repository The repository object.
     */
    public function __construct(private Repository $repository)
    {
        $this->stdClass = new StdClass();
    }

    /**
     * Sets the ClassResolver instance for the object.
     *
     * @param ClassResolver $classResolver The ClassResolver instance to set.
     * @return void
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolves the function parameter by its definition.
     *
     * @param string $name The name of the parameter.
     * @param ReflectionParameter $parameter The reflection parameter object.
     * @return mixed The resolved value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        return match (true) {
            array_key_exists($name, $this->repository->functionReference) => $this->getResolvedDefinition($name),

            !$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin() => $this->stdClass,

            array_key_exists(
                $className = $parameterType->getName(),
                $this->repository->functionReference
            ) => $this->getResolvedDefinition($className),

            default => $this->stdClass
        };
    }

    /**
     * Prepare the definition for a given name.
     *
     * @param string $name The name of the definition.
     * @return mixed The prepared definition.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function getResolvedDefinition(string $name): mixed
    {
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency detected while resolving entry '$name'");
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
                ...$this->resolve(new ReflectionFunction($definition), [], 'constructor')
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

    /**
     * Resolves the parameters of a given function.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function.
     * @param array $suppliedParameters The array of supplied parameters.
     * @param string $type The type of function ('constructor' or other).
     * @return array The processed parameters.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        $availableParams = $reflector->getParameters();
        $parameterAttribute = [];
        $applyAttribute = $this->repository->enableMethodAttribute &&
            ($type === 'constructor' xor ($reflector->class ?? null));

        if ($applyAttribute) {
            $parameterAttribute = $this->resolveMethodAttributes($reflector->getAttributes(Infuse::class));
        }

        // Resolve associative parameters
        [
            'availableParams' => $availableParams,
            'processed' => $processed,
            'availableSupply' => $suppliedParameters,
            'sort' => $sort
        ] = $this->resolveAssociativeParameters(
            $reflector,
            $availableParams,
            $type,
            $suppliedParameters,
            $parameterAttribute
        );

        // Resolve numeric/default/variadic parameters
        [
            'processed' => $numericallyProcessed,
            'variadic' => $variadic
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $availableParams,
            $suppliedParameters,
            $applyAttribute
        );

        $processed += $numericallyProcessed;

        // Resolve variadic parameters
        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        return $processed;
    }

    /**
     * Resolves the attributes of a method.
     *
     * @param array $attribute An array of method attributes.
     * @return array The resolved method attributes.
     */
    private function resolveMethodAttributes(array $attribute): array
    {
        if (!$attribute || $attribute[0]->getArguments() === []) {
            return [];
        }

        return ($attribute[0]->newInstance())->getMethodData();
    }

    /**
     * Resolves associative parameters based on the available parameters.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object for the function.
     * @param array $availableParams The array of available parameters.
     * @param string $type The type of the parameters.
     * @param array $suppliedParameters The array of supplied parameters.
     * @param array $parameterAttribute The array of parameter attributes.
     * @return array The resolved associative parameters.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute
    ): array {
        $processed = $paramsLeft = $sort = [];

        /** @var ReflectionParameter $classParameter */
        foreach ($availableParams as $key => $classParameter) {
            $parameterName = $classParameter->getName();
            $sort[$parameterName] = $key;

            if ($classParameter->isVariadic()) {
                $paramsLeft[] = $classParameter;
                break;
            }

            if (($definition = $this->resolveByDefinitionType($parameterName, $classParameter)) !== $this->stdClass) {
                $processed[$parameterName] = $definition;
                continue;
            }

            $class = $this->getResolvableClassReflection($reflector, $classParameter, $type, $processed);
            if ($class) {
                $name = $class->isInterface() ? $class->getName() : $parameterName;
                $processed[$parameterName] = $this->resolveClassDependency(
                    $class,
                    $type,
                    $suppliedParameters[$name] ?? $suppliedParameters[$parameterName] ?? null
                );
                continue;
            }

            if (array_key_exists($parameterName, $suppliedParameters)) {
                $processed[$parameterName] = $suppliedParameters[$parameterName];
                continue;
            }

            if (isset($parameterAttribute[$parameterName])) {
                $resolved = $this->resolveIndividualAttribute($classParameter, $parameterAttribute[$parameterName]);
                if ($resolved !== $this->stdClass) {
                    $processed[$parameterName] = $resolved;
                    continue;
                }
            }

            $paramsLeft[] = $classParameter;
        }

        $lastKey = array_key_last($paramsLeft);

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => match (true) {
                $lastKey !== null && $paramsLeft[$lastKey]->isVariadic() => array_diff_key(
                    $suppliedParameters,
                    $processed
                ),
                default => array_filter($suppliedParameters, 'is_int', ARRAY_FILTER_USE_KEY)
            },
            'sort' => $sort
        ];
    }

    /**
     * Retrieves the reflection class for a resolvable parameter in a given function.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection object of the function or method.
     * @param ReflectionParameter $parameter The reflection object of the parameter.
     * @param string $type The type of the resolvable parameter.
     * @param array $processed An array of already processed parameters.
     * @return ReflectionClass|null The reflection class of the resolvable parameter, or null if not resolvable.
     * @throws ContainerException|ReflectionException circular dependency or multiple instances for the same class
     */
    private function getResolvableClassReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed
    ): ?ReflectionClass {
        $parameterType = $parameter->getType();
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            return null;
        }

        $className = $parameterType->getName();
        if (($class = $parameter->getDeclaringClass()) !== null) {
            $className = match (true) {
                $className === 'self' => $class->getName(),
                $className === 'parent' && ($parent = $class->getParentClass()) => $parent->getName(),
                default => $className
            };
        }

        $reflection = $this->reflectedClass($className);

        if ($type === 'constructor' && $parameter->getDeclaringClass()?->getName() === $reflection->name) {
            throw new ContainerException("Circular dependency detected on $reflection->name");
        }

        if ($this->alreadyExist($reflection->name, $processed)) {
            throw new ContainerException(
                "Found multiple instances for $reflection->name in " .
                ($reflector->class ?? $reflector->getName()) .
                "::{$reflector->getShortName()}()"
            );
        }

        return $reflection;
    }

    /**
     * Checks if an object of the given class already exists in the array of parameters.
     *
     * @param string $class The name of the class to check for.
     * @param array $parameters The array of parameters to search in.
     * @return bool Returns true if an object of the given class exists in the array of parameters, false otherwise.
     */
    private function alreadyExist(string $class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves a class dependency.
     *
     * @param ReflectionClass $class The reflection class.
     * @param string $type The type of dependency.
     * @param mixed $supplied The supplied value.
     * @return object The resolved class instance.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        if (
            $type === 'constructor' && $supplied !== null &&
            ($constructor = $class->getConstructor()) !== null &&
            count($constructor->getParameters())
        ) {
            $this->repository->classResource[$class->getName()]['constructor']['params'] =
                array_merge(
                    $this->repository->classResource[$class->getName()]['constructor']['params'],
                    (array)$supplied
                );
        }
        return $this->classResolver->resolve($class, $supplied)['instance'];
    }

    /**
     * Resolves an individual attribute.
     *
     * @param ReflectionParameter $classParameter The reflection parameter.
     * @param string $attributeValue The attribute value.
     * @return mixed The resolved attribute.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveIndividualAttribute(ReflectionParameter $classParameter, string $attributeValue): mixed
    {
        if (($definition = $this->resolveByDefinitionType($attributeValue, $classParameter)) !== $this->stdClass) {
            return $definition;
        }

        if (function_exists($attributeValue)) {
            return $attributeValue(...$this->resolve(new ReflectionFunction($attributeValue), [], 'constructor'));
        }

        return $this->stdClass;
    }

    /**
     * Resolves the numeric & default parameters based on the available parameters.
     *
     * @param ReflectionFunctionAbstract $reflector The reflection of the function.
     * @param array $availableParams The available parameters of the function.
     * @param array $suppliedParameters The supplied parameters for the function.
     * @param bool $applyAttribute Whether to apply attribute resolution.
     * @return array The processed parameters and the variadic parameter.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveNumericDefaultParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        array $suppliedParameters,
        bool $applyAttribute
    ): array {
        $sequential = array_values($suppliedParameters);
        $processed = [];
        $variadic = [
            'type' => null,
            'value' => null
        ];

        /** @var ReflectionParameter $classParameter */
        foreach ($availableParams as $key => $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $variadic = [
                    'type' => $classParameter->getType(),
                    'value' => array_slice($suppliedParameters, $key)
                ];
                break;
            }

            if (array_key_exists($key, $sequential)) {
                $processed[$parameterName] = $sequential[$key];
                continue;
            }

            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($classParameter);
                if ($data['isResolved']) {
                    $processed[$parameterName] = $data['resolved'];
                    continue;
                }
            }

            $processed[$parameterName] = match (true) {
                $classParameter->isDefaultValueAvailable() => $classParameter->getDefaultValue(),

                $classParameter->getType() && $classParameter->allowsNull() => null,

                default => throw new ContainerException(
                    "Resolution failed for '$parameterName' in " .
                    ($reflector->class ?? $reflector->getName()) .
                    "::{$reflector->getShortName()}()"
                )
            };
        }

        return [
            'processed' => $processed,
            'variadic' => $variadic
        ];
    }

    /**
     * Resolves the parameter attribute.
     *
     * @param ReflectionParameter $classParameter The reflection parameter object.
     * @return array The resolved parameter attribute.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveParameterAttribute(ReflectionParameter $classParameter): array
    {
        $attribute = $classParameter->getAttributes(Infuse::class);
        if (!$attribute || $attribute[0]->getArguments() === []) {
            return [
                'isResolved' => false
            ];
        }

        return [
            'isResolved' => true,
            'resolved' => $this->classResolver->resolveInfuse($attribute[0]->newInstance())
                ?? throw new ContainerException(
                    "Unknown #[Infuse] parameter detected on
                    {$classParameter->getDeclaringClass()?->getName()}::\${$classParameter->getName()}"
                )
        ];
    }

    /**
     * Processes an array of values by sorting them based on a given sort array and adding any variadic values.
     *
     * @param array $processed The array of values that have already been processed.
     * @param array $variadic The array of variadic values to be added.
     * @param array $sort The array used to sort the values in $processed.
     * @return array The processed array after applying the sorting (if applicable) and adding any variadic values.
     */
    private function processVariadic(array $processed, array $variadic, array $sort): array
    {
        if (array_key_exists(0, $variadic['value'])) {
            uksort($processed, static fn ($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadic['value']));
            return $processed;
        }
        return array_merge($processed, $variadic['value']);
    }
}
