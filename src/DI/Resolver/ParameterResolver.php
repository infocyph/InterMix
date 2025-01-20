<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Responsible for resolving function/method parameters for dependency injection.
 */
class ParameterResolver
{
    private ClassResolver        $classResolver;
    private readonly IMStdClass  $stdClass;

    /**
     * Caches the final resolved parameter arrays keyed by:
     *  (reflector identity, type, and a hash of $suppliedParameters).
     */
    private array $resolvedCache = [];

    /**
     * Caches Infuse attributes per method/reflector for repeated usage.
     *
     * @var array<string, ReflectionAttribute[]>
     */
    private array $infuseCache = [];

    /**
     * @param  Repository          $repository
     * @param  DefinitionResolver  $definitionResolver
     */
    public function __construct(
        private Repository $repository,
        private readonly DefinitionResolver $definitionResolver
    ) {
        // An instance used when resolution fails or is a built-in type we won't inject
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
     * Combines the reflector identity, $type, and a hash of $suppliedParameters.
     */
    private function makeResolutionCacheKey(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): string {
        // E.g. 'OwnerClass::methodName|constructor|hashOfSuppliedParams'
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
        // e.g. "OwnerClass::methodName"
        $key = ($reflector->class ?? '').'::'.$reflector->getName();

        return $this->infuseCache[$key] ??= $reflector->getAttributes(Infuse::class);
    }

    /**
     * Resolves a function parameter by its definition name or type.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        return match (true) {
            // 1) If definition is found in the repository's functionReference
            $this->repository->hasFunctionReference($name) =>
            $this->definitionResolver->resolve($name),

            // 2) If param type is not a ReflectionNamedType => fallback to $this->stdClass
            ! $parameterType instanceof ReflectionNamedType =>
            $this->stdClass,

            // 3) If param's type name is in functionReference => resolve that class
            $this->repository->hasFunctionReference($parameterType->getName()) =>
            $this->definitionResolver->resolve($parameterType->getName()),

            // 4) Otherwise fallback to $this->stdClass
            default => $this->stdClass
        };
    }

    /**
     * Resolves the parameters of a given function or method via DI.
     *
     * @param  ReflectionFunctionAbstract  $reflector         The function/method to reflect
     * @param  array                       $suppliedParameters
     * @param  string                      $type              e.g. 'constructor' or 'method'
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        // 1) Try cached result
        $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $availableParams = $reflector->getParameters();
        $applyAttribute  = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor ($reflector->class ?? null));

        // If we apply method-level attributes, pre-fetch them
        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes(
                $this->getInfuseAttributes($reflector)
            );
        }

        // 2) Resolve associative parameters first
        [
            'availableParams' => $paramsLeft,
            'processed'       => $processed,
            'availableSupply' => $availableSupply,
            'sort'            => $sort
        ] = $this->resolveAssociativeParameters(
            $reflector,
            $availableParams,
            $type,
            $suppliedParameters,
            $attributeData
        );

        // 3) Resolve numeric/default/variadic parameters
        [
            'processed' => $numProcessed,
            'variadic'  => $variadic
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $paramsLeft,
            $availableSupply,
            $applyAttribute
        );

        // Merge them
        $processed += $numProcessed;

        // 4) Handle variadic if present
        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        // 5) Cache and return
        return $this->resolvedCache[$cacheKey] = $processed;
    }

    /**
     * Extract Infuse method-level data if present.
     *
     * Only the first attribute is used, if any.
     */
    private function resolveMethodAttributes(array $attributes): array
    {
        if (! $attributes || empty($attributes[0]->getArguments())) {
            return [];
        }

        // e.g. $attributes[0]->newInstance()->getMethodData()
        return $attributes[0]->newInstance()->getMethodData();
    }

    /**
     * Resolve "associative" (named) parameters by scanning the reflection params
     * and checking multiple strategies (definition, reflectable class, method attribute, etc.).
     *
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
        $processed  = [];
        $paramsLeft = [];
        $sort       = [];

        foreach ($availableParams as $key => $param) {
            $paramName   = $param->getName();
            $sort[$paramName] = $key;

            if ($param->isVariadic()) {
                // We break here if we hit a variadic param
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
            } else {
                // Not resolved => put param in leftover
                $paramsLeft[] = $param;
            }
        }

        // If the last param is variadic, the "availableSupply" is the rest; otherwise only numeric keys
        $lastKey = array_key_last($paramsLeft);
        $useNumeric = ($lastKey !== null && $paramsLeft[$lastKey]->isVariadic())
            ? array_diff_key($suppliedParameters, $processed)
            : array_filter($suppliedParameters, 'is_int', ARRAY_FILTER_USE_KEY);

        return [
            'availableParams' => $paramsLeft,
            'processed'       => $processed,
            'availableSupply' => $useNumeric,
            'sort'            => $sort,
        ];
    }

    /**
     * Tries to resolve an associative parameter with multiple strategies:
     *  1) By definition reference
     *  2) By reflectable class
     *  3) By method attributes array
     *  4) By checking if it's in suppliedParameters
     *
     * Returns $this->stdClass if none of these work.
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
            $nameHint = $classReflection->isInterface()
                ? $classReflection->getName()
                : $paramName;

            return $this->resolveClassDependency(
                $classReflection,
                $type,
                $suppliedParameters[$nameHint] ?? $suppliedParameters[$paramName] ?? null
            );
        }

        // 3) By method attribute data
        if (isset($parameterAttribute[$paramName])) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== $this->stdClass) {
                return $resolved;
            }
        }

        // 4) If explicitly in $suppliedParameters
        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        // Not resolved => fallback
        return $this->stdClass;
    }

    /**
     * Resolves numeric/default/variadic parameters.
     *
     * @param  ReflectionFunctionAbstract $reflector
     * @param  ReflectionParameter[]      $availableParams
     * @param  array                      $suppliedParameters
     * @param  bool                       $applyAttribute
     *
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
        $processed = [];
        $variadic  = [
            'type'  => null,
            'value' => null,
        ];

        // Convert any numeric-indexed supply to a simple list
        $sequential = array_values($suppliedParameters);

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();

            if ($param->isVariadic()) {
                $variadic = [
                    'type'  => $param->getType() instanceof ReflectionNamedType
                        ? $param->getType()
                        : null,
                    'value' => array_slice($suppliedParameters, $key),
                ];
                break;
            }

            // If there's a sequential param
            if (array_key_exists($key, $sequential)) {
                $processed[$paramName] = $sequential[$key];
                continue;
            }

            // Attempt attribute-based resolution if enabled
            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($param);
                if ($data['isResolved']) {
                    $processed[$paramName] = $data['resolved'];
                    continue;
                }
            }

            // Fallback: default value or null if allowed
            $processed[$paramName] = match (true) {
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                $param->allowsNull()              => null,
                default => throw new ContainerException(
                    "Resolution failed for '$paramName' in ".
                    ($reflector->class ?? $reflector->getName()).
                    "::{$reflector->getShortName()}()"
                )
            };
        }

        return [
            'processed' => $processed,
            'variadic'  => $variadic,
        ];
    }

    /**
     * Attempts to get a ReflectionClass for a parameter if it is a non-builtin type.
     *
     * @throws ContainerException|ReflectionException
     */
    private function getResolvableReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed
    ): ?ReflectionClass {
        $paramType = $parameter->getType();
        if (! $paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
            return null;
        }

        $className = $paramType->getName();
        if ($declaringClass = $parameter->getDeclaringClass()) {
            // If param type is 'self' or 'parent'
            $className = match ($className) {
                'self' => $declaringClass->getName(),
                'parent' => $declaringClass->getParentClass()?->getName() ?? 'parent',
                default => $className
            };
        }

        if ($className === 'parent') {
            throw new ContainerException(
                "Parameter '{$parameter->getName()}' uses 'parent' keyword but ".
                "{$parameter->getDeclaringClass()?->getName()} has no parent class."
            );
        }

        // Attempt reflection
        try {
            $reflection = ReflectionResource::getClassReflection($className);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to reflect class '{$className}' for parameter '{$parameter->getName()}'.",
                0,
                $e
            );
        }

        // Check for circular dependency in a constructor
        if (
            $type === 'constructor' &&
            $parameter->getDeclaringClass()?->getName() === $reflection->getName()
        ) {
            throw new ContainerException("Circular dependency detected on {$reflection->getName()}");
        }

        // If we already processed the same class in $processed
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
    private function alreadyExist(string $className, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $className) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves a class dependency by delegating to the ClassResolver.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        // If $type === 'constructor' and user supplied extra params,
        // we can merge them into the repository->classResource[$className]['constructor']['params'] if we wish
        if (
            $type === 'constructor' &&
            $supplied !== null &&
            $class->getConstructor() !== null
        ) {
            $existing = $this->repository->getClassResource()[$class->getName()]['constructor']['params'] ?? [];
            $this->repository->addClassResource(
                $class->getName(),
                'constructor',
                ['on' => '__constructor', 'params' => array_merge($existing, (array) $supplied)]
            );
        }

        return $this->classResolver->resolve($class)['instance'];
    }

    /**
     * Resolves an individual attribute from $parameterAttribute (#[Infuse(...)] data).
     */
    private function resolveIndividualAttribute(
        ReflectionParameter $parameter,
        string $attributeValue
    ): mixed {
        // 1) If $attributeValue is found in the repository
        $definition = $this->resolveByDefinitionType($attributeValue, $parameter);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        // 2) If $attributeValue is a function
        if (function_exists($attributeValue)) {
            $reflectionFn = ReflectionResource::getFunctionReflection($attributeValue);
            return $attributeValue(...$this->resolve($reflectionFn, [], 'constructor'));
        }

        // 3) Could not resolve => return stdClass fallback
        return $this->stdClass;
    }

    /**
     * Resolves parameter-level Infuse attributes (#[Infuse(...)] on a parameter).
     */
    private function resolveParameterAttribute(ReflectionParameter $param): array
    {
        $attribute = $param->getAttributes(Infuse::class);
        if (!$attribute || empty($attribute[0]->getArguments())) {
            return ['isResolved' => false];
        }

        // Let ClassResolver handle it
        $infuse = $attribute[0]->newInstance();
        $resolved = $this->classResolver->resolveInfuse($infuse);

        return [
            'isResolved' => (null !== $resolved),
            'resolved'   => $resolved
        ];
    }

    /**
     * Processes variadic parameters by sorting the already processed named ones
     * and appending the variadic array if any.
     */
    private function processVariadic(
        array $processed,
        array $variadic,
        array $sort
    ): array {
        // e.g. $variadic['value'] might be [arg1, arg2, ...]
        $variadicValue = (array) $variadic['value'];

        // If the variadic array is purely numeric, keep original param order
        if (isset($variadicValue[0])) {
            // Sort processed by the original reflection param order
            uksort($processed, static fn ($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadicValue));
            return $processed;
        }

        // If variadic is associative or empty, just merge
        return array_merge($processed, $variadicValue);
    }
}
