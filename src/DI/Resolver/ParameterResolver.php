<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

class ParameterResolver
{
    use Reflector;

    private ClassResolver $classResolver;

    /**
     * @param Repository $repository
     */
    public function __construct(private Repository $repository)
    {
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
     * Definition based resolver
     *
     * @param mixed $definition
     * @param string $name
     * @return mixed
     * @throws ReflectionException|ContainerException
     */
    public function resolveByDefinition(mixed $definition, string $name): mixed
    {
        return $this->repository->resolvedDefinition[$name] ??= match (true) {
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
        $refMethod = ($reflector->class ?? $reflector->getName()) . "->{$reflector->getShortName()}()";
        $availableParams = $reflector->getParameters();

        // Resolve associative parameters
        [
            'availableParams' => $availableParams,
            'processed' => $processed,
            'availableSupply' => $suppliedParameters
        ] = $this->resolveAssociativeParameters($availableParams, $type, $suppliedParameters, $refMethod);

        // Resolve numeric/default/variadic parameters
        $this->resolveNumericDefaultParameters($processed, $availableParams, $suppliedParameters, $refMethod);

        return $processed;
    }

    /**
     * Resolve associative parameters
     *
     * @param array $availableParams
     * @param string $type
     * @param array $suppliedParameters
     * @param string $refMethod
     * @return array[]
     * @throws ContainerException|ReflectionException
     */
    private function resolveAssociativeParameters(
        array $availableParams,
        string $type,
        array $suppliedParameters,
        string $refMethod
    ): array {
        $processed = $paramsLeft = [];
        foreach ($availableParams as $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $paramsLeft[] = $classParameter;
                break;
            }

            if (array_key_exists($parameterName, $this->repository->functionReference)) {
                $processed[$parameterName] = $this->resolveByDefinition(
                    $this->repository->functionReference[$parameterName],
                    $parameterName
                );
                continue;
            }

            $class = $this->getResolvableClassReflection($classParameter, $type);
            if ($class) {
                if ($this->alreadyExist($class->name, $processed)) {
                    throw new ContainerException("Found multiple instances for $class->name in $refMethod");
                }
                $processed[$parameterName] = $this->resolveClassDependency(
                    $class,
                    $type,
                    $suppliedParameters[$parameterName] ?? null
                );
                continue;
            }

            if (array_key_exists($parameterName, $suppliedParameters)) {
                $processed[$parameterName] = $suppliedParameters[$parameterName];
                continue;
            }

            $paramsLeft[] = $classParameter;
        }

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => match (true) {
                ($lastKey = array_key_last($paramsLeft)) !== null &&
                $paramsLeft[$lastKey]->isVariadic() => array_diff_key(
                    $suppliedParameters,
                    $processed
                ),

                default => array_filter($suppliedParameters, "is_int", ARRAY_FILTER_USE_KEY)
            }
        ];
    }

    /**
     * Get reflection instance, if applicable
     *
     * @param ReflectionParameter $parameter
     * @param string $type
     * @return ReflectionClass|null
     * @throws ContainerException|ReflectionException
     */
    private function getResolvableClassReflection(ReflectionParameter $parameter, string $type): ?ReflectionClass
    {
        $parameterType = $parameter->getType();
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            return null;
        }
        $reflection = $this->reflectedClass($this->getClassName($parameter, $parameterType->getName()));

        if ($type === 'constructor' && $parameter->getDeclaringClass()?->getName() === $reflection->name) {
            throw new ContainerException("Circular dependency detected: $reflection->name");
        }

        return $reflection;
    }

    /**
     * Get the class name for given type
     *
     * @param ReflectionParameter $parameter
     * @param string $name
     * @return string
     */
    private function getClassName(ReflectionParameter $parameter, string $name): string
    {
        if (($class = $parameter->getDeclaringClass()) !== null) {
            return match (true) {
                $name === 'self' => $class->getName(),
                $name === 'parent' && ($parent = $class->getParentClass()) => $parent->getName(),
                default => $name
            };
        }
        return $name;
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
     * Resolve non-associative parameters
     *
     * @param array $processed
     * @param array $availableParams
     * @param array $suppliedParameters
     * @param string $refMethod
     * @return void
     * @throws ContainerException
     */
    private function resolveNumericDefaultParameters(
        array &$processed,
        array $availableParams,
        array $suppliedParameters,
        string $refMethod
    ): void {
        $sequential = array_values($suppliedParameters);
        foreach ($availableParams as $key => $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $processed[$parameterName] = array_slice($suppliedParameters, $key);
                break;
            }

            $processed[$parameterName] = match (true) {
                array_key_exists($key, $sequential) => $sequential[$key],

                $classParameter->isDefaultValueAvailable() => $classParameter->getDefaultValue(),

                $classParameter->getType() && $classParameter->allowsNull() => null,

                default => throw new ContainerException(
                    "Resolution failed for '$parameterName' in $refMethod"
                )
            };
        }
    }
}
