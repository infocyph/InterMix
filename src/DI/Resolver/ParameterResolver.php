<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesAssociativeParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesNumericAndVariadicParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesParameterAttributes;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Handles parameter resolution for dependency injection.
 *
 * This resolver is responsible for resolving method and function parameters
 * with full support for dependency injection, type hinting, and attributes.
 * It processes parameter attributes, handles variadic parameters, and supports
 * both named and positional parameter passing.
 *
 * Features:
 * - Attribute-based parameter configuration
 * - Type-aware resolution with union/intersection types
 * - Caching for performance optimization
 * - Support for associative, numeric, and variadic parameters
 */
class ParameterResolver
{
    use ResolvesAssociativeParameters;
    use ResolvesNumericAndVariadicParameters;
    use ResolvesParameterAttributes;

    private const int INFUSE_CACHE_LIMIT = 1024;

    private const int PARAM_ATTRIBUTE_PLAN_CACHE_LIMIT = 4096;

    private const int RESOLUTION_PLAN_CACHE_LIMIT = 2048;

    private readonly IMStdClass $stdClass;

    private ClassResolver $classResolver;

    /** @var array<string, array<int, ReflectionAttribute<Infuse>>> */
    private array $infuseCache = [];

    /** @var array<string, array{
     *   infuse: array<int, ReflectionAttribute<Infuse>>,
     *   all: array<int, ReflectionAttribute<object>>
     * }>
     */
    private array $parameterAttributePlanCache = [];

    /** @var array<string, array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, string>
     * }>
     */
    private array $resolutionPlanCache = [];

    public function __construct(
        private readonly Repository $repository,
        private readonly DefinitionResolver $definitionResolver,
    ) {
        $this->stdClass = new IMStdClass();
    }

    /**
     * @param array<int|string, mixed> $suppliedParameters
     * @return array<int|string, mixed>
     *
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type,
    ): array {
        $this->repository->tracer()->push(
            "{$reflector->getShortName()}() params",
            TraceLevelEnum::Verbose,
        );

        $plan = $this->getResolutionPlan($reflector, $type);
        $availableParams = $plan['availableParams'];
        if (!$availableParams) {
            return [];
        }

        $applyAttribute = $plan['applyAttribute'];
        $attributeData = $plan['attributeData'];

        [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => $availableSupply,
            'sort' => $sort,
        ] = $this->resolveAssociativeParameters(
            $reflector,
            $availableParams,
            $type,
            $suppliedParameters,
            $attributeData,
        );
        if (!$paramsLeft) {
            return $processed;
        }

        [
            'processed' => $numProcessed,
            'variadic' => $variadic,
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $paramsLeft,
            $availableSupply,
            $applyAttribute,
        );
        $processed += $numProcessed;

        if ($variadic['value'] !== null) {
            $processed = $this->processVariadic($processed, $variadic, $sort);
        }

        return $processed;
    }

    /**
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $parameterType = $parameter->getType();

        if ($this->repository->hasFunctionReference($name)) {
            return $this->definitionResolver->resolve($name);
        }

        $namedTypes = match (true) {
            $parameterType instanceof ReflectionNamedType => [$parameterType],
            $parameterType instanceof ReflectionUnionType, $parameterType instanceof ReflectionIntersectionType => $parameterType->getTypes(),
            default => [],
        };

        foreach ($namedTypes as $named) {
            if (!$named instanceof ReflectionNamedType) {
                continue;
            }
            $typeName = $named->getName();

            if ($this->repository->hasFunctionReference($typeName)) {
                return $this->definitionResolver->resolve($typeName);
            }
        }

        return $this->stdClass;
    }

    /**
     * @param ReflectionClass<object> $dependency
     */
    public function resolveContextualDependency(string $consumer, ReflectionClass $dependency): mixed
    {
        if ($consumer === '') {
            return $this->stdClass;
        }

        $binding = $this->repository->getContextualBinding($consumer, $dependency->getName());
        if ($binding === null) {
            return $this->stdClass;
        }

        if (is_callable($binding)) {
            return $binding($this->repository->container());
        }

        if (is_string($binding)) {
            if ($this->repository->hasFunctionReference($binding)) {
                return $this->definitionResolver->resolve($binding);
            }

            if (class_exists($binding) || interface_exists($binding)) {
                $resolvedClass = $this->applyEnvOverride($binding);

                return $this->classResolver->resolveClassInstance(
                    ReflectionResource::getClassReflection($resolvedClass),
                );
            }
        }

        if (is_object($binding) && is_a($binding, $dependency->getName())) {
            return $binding;
        }

        return $binding;
    }

    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    private function alreadyExist(string $className, array $parameters): bool
    {
        return array_any($parameters, fn($value) => $value instanceof $className);
    }

    private function applyEnvOverride(string $fqcn): string
    {
        if (interface_exists($fqcn)) {
            $concrete = $this->repository->getEnvConcrete($fqcn);
            if ($concrete && class_exists($concrete)) {
                return $concrete;
            }
        }

        return $fqcn;
    }

    /**
     * @template TValue
     * @param array<string, TValue> $cache
     */
    private function evictCacheKeyIfNeeded(array &$cache, string $key, int $limit): void
    {
        if (!array_key_exists($key, $cache) && count($cache) >= $limit) {
            $firstKey = array_key_first($cache);
            if (is_string($firstKey)) {
                unset($cache[$firstKey]);
            }
        }
    }

    /**
     * @return array<int, ReflectionNamedType>
     */
    private function extractNamedTypeCandidates(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $types = [];
            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType) {
                    $types[] = $candidate;
                }
            }

            return $types;
        }

        return [];
    }

    /**
     * @return array<int, ReflectionAttribute<Infuse>>
     */
    private function getInfuseAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $key = $this->ownerFor($reflector) . '::' . $reflector->getName();
        if (array_key_exists($key, $this->infuseCache)) {
            return $this->infuseCache[$key];
        }

        return $this->rememberInfuse(
            $key,
            $reflector->getAttributes(Infuse::class),
        );
    }

    /**
     * @return array{
     *   infuse: array<int, ReflectionAttribute<Infuse>>,
     *   all: array<int, ReflectionAttribute<object>>
     * }
     */
    private function getParameterAttributePlan(ReflectionParameter $parameter): array
    {
        $key = $this->makeParameterAttributePlanKey($parameter);
        if (array_key_exists($key, $this->parameterAttributePlanCache)) {
            return $this->parameterAttributePlanCache[$key];
        }

        return $this->rememberParameterAttributePlan($key, [
            'infuse' => $parameter->getAttributes(Infuse::class),
            'all' => $parameter->getAttributes(),
        ]);
    }

    /**
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, string>
     * }
     */
    private function getResolutionPlan(ReflectionFunctionAbstract $reflector, string $type): array
    {
        $key = $this->makeResolutionPlanKey($reflector, $type);
        if (array_key_exists($key, $this->resolutionPlanCache)) {
            return $this->resolutionPlanCache[$key];
        }

        $isMethod = $reflector instanceof ReflectionMethod;
        $applyAttribute = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor $isMethod);

        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes($this->getInfuseAttributes($reflector));
        }

        return $this->rememberResolutionPlan($key, [
            'availableParams' => $reflector->getParameters(),
            'applyAttribute' => $applyAttribute,
            'attributeData' => $attributeData,
        ]);
    }

    /**
     * @param array<int|string, mixed> $processed
     * @return ReflectionClass<object>|null
     *
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function getResolvableReflection(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed,
    ): ?ReflectionClass {
        $candidates = $this->extractNamedTypeCandidates($parameter);
        $className = $this->pickResolvableType($candidates);

        if (!$className) {
            return null;
        }

        $className = $this->normalizeSelfParent($className, $parameter->getDeclaringClass());
        $className = $this->applyEnvOverride($className);
        $reflection = ReflectionResource::getClassReflection($className);

        if ($type === 'constructor'
            && $parameter->getDeclaringClass()?->getName() === $reflection->getName()) {
            throw new ContainerException("Circular dependency on {$reflection->getName()}");
        }

        if ($this->alreadyExist($reflection->getName(), $processed)) {
            $owner = $this->ownerFor($reflector);

            throw new ContainerException(
                "Multiple instances for {$reflection->getName()} in {$owner}::{$reflector->getShortName()}()",
            );
        }

        return $reflection;
    }

    private function makeParameterAttributePlanKey(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();
        $owner = $this->ownerFor($function);
        $signature = ReflectionResource::getSignature($function);

        return $owner . '::' . $function->getName() . '|p:' . $parameter->getPosition() . '|sig:' . $signature;
    }

    private function makeResolutionPlanKey(ReflectionFunctionAbstract $reflector, string $type): string
    {
        $owner = $this->ownerFor($reflector);
        $signature = ReflectionResource::getSignature($reflector);
        $methodAttrEnabled = $this->repository->isMethodAttributeEnabled() ? '1' : '0';

        return $owner . '::' . $reflector->getName() . "|$type|ma:$methodAttrEnabled|sig:$signature";
    }

    /**
     * @param ReflectionClass<object>|null $declaring
     *
     * @throws ContainerException
     */
    private function normalizeSelfParent(
        string $className,
        ?ReflectionClass $declaring,
    ): string {
        if ($className === 'self') {
            return $declaring?->getName() ?? $className;
        }

        if ($className === 'parent') {
            $parent = $declaring?->getParentClass();
            if (!$parent instanceof ReflectionClass) {
                throw new ContainerException("Parameter uses 'parent' but no parent class found.");
            }

            return $parent->getName();
        }

        return $className;
    }

    private function ownerFor(ReflectionFunctionAbstract $reflector): string
    {
        return $reflector instanceof ReflectionMethod
            ? $reflector->getDeclaringClass()->getName()
            : '';
    }

    /**
     * @param array<int, ReflectionNamedType> $candidates
     */
    private function pickResolvableType(array $candidates): ?string
    {
        foreach ($candidates as $namedType) {
            if ($namedType->isBuiltin()) {
                continue;
            }
            $name = $namedType->getName();

            if ($this->repository->hasFunctionReference($name)) {
                return $name;
            }

            if (class_exists($name) || interface_exists($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<int, ReflectionAttribute<Infuse>> $value
     * @return array<int, ReflectionAttribute<Infuse>>
     */
    private function rememberInfuse(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->infuseCache, $key, self::INFUSE_CACHE_LIMIT);
        $this->infuseCache[$key] = $value;

        return $value;
    }

    /**
     * @param array{
     *   infuse: array<int, ReflectionAttribute<Infuse>>,
     *   all: array<int, ReflectionAttribute<object>>
     * } $value
     * @return array{
     *   infuse: array<int, ReflectionAttribute<Infuse>>,
     *   all: array<int, ReflectionAttribute<object>>
     * }
     */
    private function rememberParameterAttributePlan(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->parameterAttributePlanCache, $key, self::PARAM_ATTRIBUTE_PLAN_CACHE_LIMIT);
        $this->parameterAttributePlanCache[$key] = $value;

        return $value;
    }

    /**
     * @param array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, string>
     * } $value
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, string>
     * }
     */
    private function rememberResolutionPlan(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->resolutionPlanCache, $key, self::RESOLUTION_PLAN_CACHE_LIMIT);
        $this->resolutionPlanCache[$key] = $value;

        return $value;
    }
}
