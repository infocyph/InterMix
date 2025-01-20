<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Responsible for resolving function/method parameters for DI,
 * possibly logging debug info, and checking environment-based overrides.
 */
class ParameterResolver
{
    private ClassResolver $classResolver;
    private readonly IMStdClass $stdClass;

    /**
     * Caches the final resolved parameter arrays keyed by:
     *   (reflector identity, type, hash of $suppliedParameters).
     */
    private array $resolvedCache = [];

    /**
     * Caches Infuse attributes per method/reflector.
     *
     * @var array<string, ReflectionAttribute[]>
     */
    private array $infuseCache = [];

    public function __construct(
        private Repository $repository,
        private readonly DefinitionResolver $definitionResolver
    ) {
        // Fallback placeholder for unresolvable references
        $this->stdClass = new IMStdClass();
    }

    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Creates a cache key for calls to resolve().
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
     * Fetch Infuse attributes from the reflection object (cached).
     */
    private function getInfuseAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $key = ($reflector->class ?? '').'::'.$reflector->getName();
        return $this->infuseCache[$key] ??= $reflector->getAttributes(Infuse::class);
    }

    /**
     * Resolves the function parameter by name or reflection type,
     * checking environment-based overrides if it’s an interface.
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        // 1) Check if $name is in functionReference (like older logic)
        if ($this->repository->hasFunctionReference($name)) {
            return $this->definitionResolver->resolve($name);
        }

        // 2) If type is not ReflectionNamedType => fallback to stdClass
        if (! $parameterType instanceof ReflectionNamedType) {
            return $this->stdClass;
        }

        $typeName = $parameterType->getName();

        // 3) If typeName is in functionReference => resolve that
        if ($this->repository->hasFunctionReference($typeName)) {
            return $this->definitionResolver->resolve($typeName);
        }

        // 4) Otherwise fallback to stdClass
        return $this->stdClass;
    }

    /**
     * Main method: resolves function/method parameters.
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $availableParams = $reflector->getParameters();
        $applyAttribute  = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor ($reflector->class ?? null));

        // Possibly log debug
        if ($this->repository->isDebug()) {
            $owner = $reflector->class ?? $reflector->getName();
            // You might do some logging or store debug messages
            // e.g. error_log("ParameterResolver: Resolving params for $owner::$type");
        }

        // if method-level Infuse attributes exist
        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes(
                $this->getInfuseAttributes($reflector)
            );
        }

        // 1) Resolve associative params
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

        // 2) Resolve numeric/default/variadic
        [
            'processed' => $numProcessed,
            'variadic'  => $variadic
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $paramsLeft,
            $availableSupply,
            $applyAttribute
        );

        $processed += $numProcessed;

        // 3) If we have a variadic param
        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        return $this->resolvedCache[$cacheKey] = $processed;
    }

    private function resolveMethodAttributes(array $attributes): array
    {
        if (!$attributes || empty($attributes[0]->getArguments())) {
            return [];
        }
        // e.g. $attributes[0]->newInstance()->getMethodData()
        return $attributes[0]->newInstance()->getMethodArguments();
    }

    /**
     * Resolve "associative" parameters by scanning reflection parameters
     * and checking definition references, class reflection, method attributes, etc.
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
            $paramName = $param->getName();
            $sort[$paramName] = $key;

            if ($param->isVariadic()) {
                $paramsLeft[] = $param;
                break;
            }

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
                $paramsLeft[] = $param;
            }
        }

        $lastKey = array_key_last($paramsLeft);
        $useNumeric = ($lastKey !== null && $paramsLeft[$lastKey]->isVariadic())
            ? array_diff_key($suppliedParameters, $processed)
            : array_filter($suppliedParameters, 'is_int', ARRAY_FILTER_USE_KEY);

        return [
            'availableParams' => $paramsLeft,
            'processed'       => $processed,
            'availableSupply' => $useNumeric,
            'sort'            => $sort
        ];
    }

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

        // 2) Possibly environment-based interface => concrete
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

        // 3) Method-level attribute array
        if (isset($parameterAttribute[$paramName])) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== $this->stdClass) {
                return $resolved;
            }
        }

        // 4) If explicitly in suppliedParameters
        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        return $this->stdClass;
    }

    private function resolveNumericDefaultParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        array $suppliedParameters,
        bool $applyAttribute
    ): array {
        $processed = [];
        $variadic  = ['type' => null, 'value' => null];
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

            if (array_key_exists($key, $sequential)) {
                $processed[$paramName] = $sequential[$key];
                continue;
            }

            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($param);
                if ($data['isResolved']) {
                    $processed[$paramName] = $data['resolved'];
                    continue;
                }
            }

            // fallback to default or null
            $processed[$paramName] = match (true) {
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                $param->allowsNull() => null,
                default => throw new ContainerException(
                    "Resolution failed for '$paramName' in ".
                    ($reflector->class ?? $reflector->getName()).
                    "::{$reflector->getShortName()}()"
                )
            };
        }

        return [
            'processed' => $processed,
            'variadic'  => $variadic
        ];
    }

    private function getResolvableReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed
    ): ?ReflectionClass {
        $paramType = $parameter->getType();
        if (!$paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
            return null;
        }

        $className = $paramType->getName();
        if ($declaringClass = $parameter->getDeclaringClass()) {
            $className = match ($className) {
                'self' => $declaringClass->getName(),
                'parent' => $declaringClass->getParentClass()?->getName() ?? 'parent',
                default => $className
            };
        }
        if ($className === 'parent') {
            throw new ContainerException(
                "Parameter '{$parameter->getName()}' uses 'parent' keyword but no parent class."
            );
        }
        // (optional) environment-based interface => concrete override
        // e.g. if $className is an interface, check $this->repository->getEnvConcrete($className)
        $envConcrete = null;
        if (interface_exists($className)) {
            $envConcrete = $this->repository->getEnvConcrete($className);
        }
        $finalClassName = $envConcrete ?: $className;

        try {
            $reflection = ReflectionResource::getClassReflection($finalClassName);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to reflect class '{$finalClassName}' for parameter '{$parameter->getName()}'.",
                0,
                $e
            );
        }

        if ($type === 'constructor' &&
            $parameter->getDeclaringClass()?->getName() === $reflection->getName()
        ) {
            throw new ContainerException("Circular dependency on {$reflection->getName()}");
        }

        if ($this->alreadyExist($reflection->getName(), $processed)) {
            $shortName = $reflector->getShortName();
            $owner     = $reflector->class ?? $reflector->getName();
            throw new ContainerException(
                "Multiple instances for {$reflection->getName()} in $owner::$shortName()"
            );
        }

        return $reflection;
    }

    private function alreadyExist(string $className, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $className) {
                return true;
            }
        }
        return false;
    }

    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        if ($type === 'constructor' && $supplied !== null && $class->getConstructor()) {
            $existing = $this->repository->getClassResource()[$class->getName()]['constructor']['params'] ?? [];
            $merged   = array_merge($existing, (array) $supplied);
            $this->repository->addClassResource(
                $class->getName(),
                'constructor',
                ['on' => '__constructor','params' => $merged]
            );
        }
        return $this->classResolver->resolve($class)['instance'];
    }

    private function resolveIndividualAttribute(
        ReflectionParameter $param,
        string $attributeValue
    ): mixed {
        // 1) If $attributeValue in functionReference
        $definition = $this->resolveByDefinitionType($attributeValue, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }
        // 2) If $attributeValue is a function
        if (function_exists($attributeValue)) {
            $reflectionFn = ReflectionResource::getFunctionReflection($attributeValue);
            return $attributeValue(...$this->resolve($reflectionFn, [], 'constructor'));
        }
        return $this->stdClass;
    }

    private function resolveParameterAttribute(ReflectionParameter $param): array
    {
        $attribute = $param->getAttributes(Infuse::class);
        if (!$attribute || empty($attribute[0]->getArguments())) {
            return ['isResolved' => false];
        }
        $infuseInstance = $attribute[0]->newInstance();
        $resolved = $this->classResolver->resolveInfuse($infuseInstance);
        return [
            'isResolved' => (null !== $resolved),
            'resolved'   => $resolved
        ];
    }

    private function processVariadic(
        array $processed,
        array $variadic,
        array $sort
    ): array {
        $variadicValue = (array) $variadic['value'];
        if (isset($variadicValue[0])) {
            uksort($processed, static fn ($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadicValue));
            return $processed;
        }
        return array_merge($processed, $variadicValue);
    }
}
