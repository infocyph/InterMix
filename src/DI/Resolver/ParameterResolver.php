<?php

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The ParameterResolver is responsible for resolving
 * function/method parameters for dependency injection.
 */
class ParameterResolver
{
    use Reflector;

    private ClassResolver $classResolver;

    private readonly IMStdClass $stdClass;

    /**
     * @var array<string, array> Caches the final resolved parameter arrays per (reflector, arguments).
     */
    private array $resolvedCache = [];

    /**
     * @var array<string, array<ReflectionAttribute>> Caches Infuse attributes per method/reflector.
     */
    private array $infuseCache = [];

    /**
     * Constructs a new instance of the class.
     */
    public function __construct(
        private Repository $repository,
        private readonly DefinitionResolver $definitionResolver
    ) {
        $this->stdClass = new IMStdClass();
    }

    /**
     * Sets the ClassResolver instance for the object.
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Creates a cache key for calls to resolve().
     * Combines the reflector identity, type, and a hash of $suppliedParameters.
     */
    private function makeResolutionCacheKey(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): string {
        $owner = $reflector->class ?? '';
        $argsHash = hash('xxh3', serialize($suppliedParameters));

        return "$owner::{$reflector->getName()}|$type|$argsHash";
    }

    /**
     * Returns Infuse attributes for a given reflector, using an internal cache.
     *
     * @return ReflectionAttribute[]
     */
    private function getInfuseAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $key = ($reflector->class ?? '').'::'.$reflector->getName();

        return $this->infuseCache[$key] ??= $reflector->getAttributes(Infuse::class);
    }

    /**
     * Resolves the function parameter by its definition.
     *
     * - **Implements #5**: Assumes "never attempt to inject built-in PHP types."
     *
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        return match (true) {
            // 1) If definition is found in functionReference
            isset($this->repository->functionReference[$name]) => $this->definitionResolver->resolve($name),

            // 2) If not a ReflectionNamedType => fallback to $this->stdClass
            ! $parameterType instanceof ReflectionNamedType => $this->stdClass,

            // 3) If className is registered in functionReference
            isset($this->repository->functionReference[$parameterType->getName()]) => $this->definitionResolver->resolve($parameterType->getName()),

            // 4) Otherwise
            default => $this->stdClass
        };
    }

    /**
     * Resolves the attributes of a method.
     *
     * @param  ReflectionAttribute[]  $attribute
     */
    private function resolveMethodAttributes(array $attribute): array
    {
        // Early return for no/empty attributes
        if ($attribute && $attribute[0]->getArguments() !== []) {
            return $attribute[0]->newInstance()->getMethodData();
        }

        return [];
    }

    /**
     * Resolves the parameters of a given function.
     *
     * - **Implements #2**: Partial caching of final resolution arrays.
     * - **Implements #7**: Caching of Infuse attributes (via getInfuseAttributes()).
     *
     * @param  array  $suppliedParameters  (numeric or associative)
     * @param  string  $type  e.g. 'constructor'
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        // 1) Attempt to retrieve from cache first
        $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $availableParams = $reflector->getParameters();
        $applyAttribute = $this->repository->enableMethodAttribute &&
            ($type === 'constructor' xor ($reflector->class ?? null));

        // If we plan to apply method-level attributes, pre-fetch them from the cache
        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes($this->getInfuseAttributes($reflector));
        }

        // 2) Resolve associative parameters
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
            $attributeData
        );

        // 3) Resolve numeric/default/variadic parameters
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

        // 4) Process variadic parameters if present
        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        // 5) Store final resolved data in cache
        return $this->resolvedCache[$cacheKey] = $processed;
    }

    /**
     * Resolves associative parameters.
     *
     * @param  ReflectionParameter[]  $availableParams
     * @return array{
     *   availableParams: ReflectionParameter[],
     *   processed: array<string,mixed>,
     *   availableSupply: array<int|string,mixed>,
     *   sort: array<string,int>
     * }
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute
    ): array {
        $processed = [];
        $paramsLeft = [];
        $sort = [];

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();
            $sort[$paramName] = $key;

            if ($param->isVariadic()) {
                $paramsLeft[] = $param;
                break;
            }

            // Attempt resolution by multiple strategies
            $resolvedValue = $this->tryResolveAssociative(
                $reflector,
                $param,
                $type,
                $suppliedParameters,
                $parameterAttribute,
                $processed
            );

            if ($resolvedValue !== $this->stdClass) {
                $processed[$paramName] = $resolvedValue;

                continue;
            }

            // If not resolved, push to leftover
            $paramsLeft[] = $param;
        }

        $lastKey = array_key_last($paramsLeft);

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => match (true) {
                $lastKey !== null && $paramsLeft[$lastKey]->isVariadic() => array_diff_key($suppliedParameters, $processed),
                default => array_filter($suppliedParameters, 'is_int', ARRAY_FILTER_USE_KEY)
            },
            'sort' => $sort,
        ];
    }

    /**
     * Tries to resolve an associative parameter through multiple strategies:
     *  1) By definition reference
     *  2) By reflectable class
     *  3) By method attributes array
     *  4) By checking if it's in suppliedParameters
     *
     * Returns $this->stdClass if none of the strategies succeed.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function tryResolveAssociative(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $param,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
        array $processed
    ): mixed {
        $paramName = $param->getName();

        // 1) By definition reference
        $definition = $this->resolveByDefinitionType($paramName, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        // 2) By reflectable class
        $classReflection = $this->getResolvableReflection($reflector, $param, $type, $processed);
        if ($classReflection) {
            $nameHint = $classReflection->isInterface() ? $classReflection->getName() : $paramName;

            return $this->resolveClassDependency(
                $classReflection,
                $type,
                $suppliedParameters[$nameHint] ?? $suppliedParameters[$paramName] ?? null
            );
        }

        // 3) By method attributes array
        if (isset($parameterAttribute[$paramName])) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== $this->stdClass) {
                return $resolved;
            }
        }

        // 4) If it exists in the suppliedParameters by name
        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        // No resolution
        return $this->stdClass;
    }

    /**
     * Resolves the numeric & default parameters based on the available parameters.
     *
     * @param  ReflectionParameter[]  $availableParams
     * @return array{
     *   processed: array<string,mixed>,
     *   variadic: array{type: ?ReflectionNamedType, value: mixed}
     * }
     *
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
            'value' => null,
        ];

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();

            if ($param->isVariadic()) {
                $variadic = [
                    'type' => $param->getType(),
                    'value' => array_slice($suppliedParameters, $key),
                ];
                break;
            }

            // If there's a sequential param available (numeric indexing)
            if (array_key_exists($key, $sequential)) {
                $processed[$paramName] = $sequential[$key];

                continue;
            }

            // Optionally try attribute-based resolution
            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($param);
                if ($data['isResolved']) {
                    $processed[$paramName] = $data['resolved'];

                    continue;
                }
            }

            // Fallback to default value or null if allowed, otherwise throw
            $processed[$paramName] = match (true) {
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),

                $param->getType() && $param->allowsNull() => null,

                default => throw new ContainerException(
                    "Resolution failed for '$paramName' in ".
                    ($reflector->class ?? $reflector->getName()).
                    "::{$reflector->getShortName()}()"
                )
            };
        }

        return [
            'processed' => $processed,
            'variadic' => $variadic,
        ];
    }

    /**
     * Retrieves the reflection class for a resolvable parameter in a given function.
     *
     * @throws ContainerException|ReflectionException
     */
    private function getResolvableReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed
    ): ?ReflectionClass {
        $parameterType = $parameter->getType();

        if (! $parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            return null;
        }

        $className = $parameterType->getName();
        if ($declaringClass = $parameter->getDeclaringClass()) {
            $className = match (true) {
                $className === 'self' => $declaringClass->getName(),
                $className === 'parent'
                && ($parent = $declaringClass->getParentClass()) => $parent->getName(),
                default => $className
            };
        }

        // Handle the case where 'parent' is used but there is no parent class
        if ($className === 'parent') {
            throw new ContainerException(
                "Parameter '{$parameter->getName()}' uses 'parent' keyword but '{$declaringClass->getName()}' has no parent class."
            );
        }

        try {
            $reflection = $this->reflectedClass($className);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to reflect class '{$className}' for parameter '{$parameter->getName()}'.",
                0,
                $e
            );
        }

        // Check for circular dependency
        if (
            $type === 'constructor' &&
            $parameter->getDeclaringClass()?->getName() === $reflection->getName()
        ) {
            throw new ContainerException("Circular dependency detected on {$reflection->getName()}");
        }

        // Check if we already processed that class
        if ($this->alreadyExist($reflection->getName(), $processed)) {
            $shortName = $reflector->getShortName();
            $owner = $reflector->class ?? $reflector->getName();

            throw new ContainerException(
                "Found multiple instances for {$reflection->getName()} in $owner::$shortName()"
            );
        }

        return $reflection;
    }

    /**
     * Checks if an object of the given class already exists in the array of parameters.
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
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        // Merge user-supplied constructor params into the repository if needed
        if (
            $type === 'constructor' &&
            $supplied !== null &&
            ($constructor = $class->getConstructor()) !== null &&
            count($constructor->getParameters())
        ) {
            $this->repository->classResource[$class->getName()]['constructor']['params'] = array_merge(
                $this->repository->classResource[$class->getName()]['constructor']['params'],
                (array) $supplied
            );
        }

        return $this->classResolver->resolve($class, $supplied)['instance'];
    }

    /**
     * Resolves an individual attribute (via attribute value or callable).
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveIndividualAttribute(
        ReflectionParameter $classParameter,
        string $attributeValue
    ): mixed {
        // 1) Try definition-based resolution
        $definition = $this->resolveByDefinitionType($attributeValue, $classParameter);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        // 2) If attributeValue is a callable function name
        if (function_exists($attributeValue)) {
            return $attributeValue(
                ...$this->resolve(new ReflectionFunction($attributeValue), [], 'constructor')
            );
        }

        // 3) Could not resolve
        return $this->stdClass;
    }

    /**
     * Resolves the parameter attribute: #[Infuse(...)]
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveParameterAttribute(ReflectionParameter $classParameter): array
    {
        $attribute = $classParameter->getAttributes(Infuse::class);
        if (! $attribute || $attribute[0]->getArguments() === []) {
            return ['isResolved' => false];
        }

        return [
            'isResolved' => true,
            'resolved' => $this->classResolver->resolveInfuse($attribute[0]->newInstance())
                ?? throw new ContainerException(
                    'Unknown #[Infuse] parameter detected on '.
                    "{$classParameter->getDeclaringClass()?->getName()}::\${$classParameter->getName()}"
                ),
        ];
    }

    /**
     * Processes an array of values by sorting them based on a given sort array
     * and adding any variadic values.
     *
     * - **Implements #4.3**: Ensures that we merge arrays in-place.
     * - Also ensures $variadic['value'] is always an array before merging it.
     *
     * @param  array{type: ?ReflectionNamedType, value: mixed}  $variadic
     * @param  array<string,int>  $sort
     */
    private function processVariadic(array $processed, array $variadic, array $sort): array
    {
        // Ensure the variadic value is an array
        $variadicValue = (array) $variadic['value'];

        // If the variadic array is purely numeric, keep original param order
        if (isset($variadicValue[0])) {
            uksort($processed, static fn ($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);

            array_push($processed, ...array_values($variadicValue));

            return $processed;
        }

        // If variadic is associative or empty, just merge
        return array_merge($processed, $variadicValue);
    }
}
