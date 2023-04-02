<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
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

    /**
     * @param Repository $repository
     */
    public function __construct(private Repository $repository)
    {
        $this->stdClass = new StdClass();
    }

    /**
     * Set class resolver instance
     *
     * @param ClassResolver $classResolver
     * @return void
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolve by definition (with type preparation)
     *
     * @param string $name
     * @param ReflectionParameter $parameter
     * @return mixed
     * @throws ContainerException|ReflectionException
     */
    public function resolveByDefinition(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        return match (true) {
            array_key_exists($name, $this->repository->functionReference) => $this->prepareDefinition($name),

            !$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin() => $this->stdClass,

            array_key_exists(
                $className = $parameterType->getName(),
                $this->repository->functionReference
            ) => $this->prepareDefinition($className),

            default => $this->stdClass
        };
    }

    /**
     * Definition based resolver
     *
     * @param string $name
     * @return mixed
     * @throws ReflectionException|ContainerException
     */
    public function prepareDefinition(string $name): mixed
    {
        if (array_key_exists($name, $this->repository->resolvedDefinition)) {
            return $this->repository->resolvedDefinition[$name];
        }

        $definition = $this->repository->functionReference[$name];

        return $this->repository->resolvedDefinition[$name] = match (true) {
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
     * Resolve Function parameter
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $suppliedParameters
     * @param string $type
     * @return array
     * @throws ReflectionException|ContainerException
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
            'availableSupply' => $suppliedParameters
        ] = $this->resolveAssociativeParameters(
            $reflector,
            $availableParams,
            $type,
            $suppliedParameters,
            $parameterAttribute
        );

        // Resolve numeric/default/variadic parameters
        $processed += $this->resolveNumericDefaultParameters(
            $reflector,
            $availableParams,
            $suppliedParameters,
            $applyAttribute
        );

        return $processed;
    }

    /**
     * Resolve attribute arguments
     *
     * @param array $attribute
     * @return array
     */
    private function resolveMethodAttributes(array $attribute): array
    {
        if (!$attribute || $attribute[0]->getArguments() === []) {
            return [];
        }

        return ($attribute[0]->newInstance())->getMethodData();
    }

    /**
     * Resolve associative parameters
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $availableParams
     * @param string $type
     * @param array $suppliedParameters
     * @param array $parameterAttribute
     * @return array[]
     * @throws ContainerException|ReflectionException
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute
    ): array {
        $processed = $paramsLeft = [];

        /** @var ReflectionParameter $classParameter */
        foreach ($availableParams as $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $paramsLeft[] = $classParameter;
                break;
            }

            if (($definition = $this->resolveByDefinition($parameterName, $classParameter)) !== $this->stdClass) {
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
            }
        ];
    }

    /**
     * Get reflection instance, if applicable
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param ReflectionParameter $parameter
     * @param string $type
     * @param array $processed
     * @return ReflectionClass|null
     * @throws ContainerException|ReflectionException
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
                sprintf(
                    "Found multiple instances for %s in %s::%s()",
                    $reflection->name,
                    $reflector->class ?? $reflector->getName(),
                    $reflector->getShortName()
                )
            );
        }

        return $reflection;
    }

    /**
     * Check if parameter already resolved
     *
     * @param string $class
     * @param array $parameters
     * @return bool
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
     * Resolve class dependency
     *
     * @param ReflectionClass $class
     * @param string $type
     * @param mixed $supplied
     * @return object
     * @throws ReflectionException|ContainerException
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
     * Resolve attribute for method
     *
     * @param ReflectionParameter $classParameter
     * @param string $attributeValue
     * @return mixed
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveIndividualAttribute(ReflectionParameter $classParameter, string $attributeValue): mixed
    {
        if (($definition = $this->resolveByDefinition($attributeValue, $classParameter)) !== $this->stdClass) {
            return $definition;
        }

        if (function_exists($attributeValue)) {
            return $attributeValue(...$this->resolve(new ReflectionFunction($attributeValue), [], 'constructor'));
        }

        return $this->stdClass;
    }

    /**
     * Resolve non-associative parameters
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $availableParams
     * @param array $suppliedParameters
     * @param bool $applyAttribute
     * @return array
     * @throws ContainerException|ReflectionException
     */
    private function resolveNumericDefaultParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        array $suppliedParameters,
        bool $applyAttribute
    ): array {
        $sequential = array_values($suppliedParameters);
        $processed = [];

        /** @var ReflectionParameter $classParameter */
        foreach ($availableParams as $key => $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $processed[$parameterName] = array_slice($suppliedParameters, $key);
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
                    sprintf(
                        "Resolution failed for '%s' in %s::%s()",
                        $parameterName,
                        $reflector->class ?? $reflector->getName(),
                        $reflector->getShortName()
                    )
                )
            };
        }

        return $processed;
    }

    /**
     * Resolve parameter attribute
     *
     * @param ReflectionParameter $classParameter
     * @return array|false[]
     * @throws ContainerException|ReflectionException
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
                    sprintf(
                        "Unknown #[Infuse] parameter detected on %s::$%s",
                        $classParameter->getDeclaringClass()->getName(),
                        $classParameter->getName()
                    )
                )
        ];
    }
}
