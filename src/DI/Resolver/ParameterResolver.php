<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesAssociativeParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesNumericAndVariadicParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesParameterAttributes;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use WeakMap;

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

    private const int INJECT_CACHE_LIMIT = 1024;

    private const int PARAM_ATTRIBUTE_PLAN_CACHE_LIMIT = 4096;

    private const int RESOLUTION_PLAN_CACHE_LIMIT = 2048;

    private ClassResolver $classResolver;

    /** @var WeakMap<Closure, array<int, ReflectionAttribute<Inject>>> */
    private WeakMap $closureInjectCache;

    /** @var WeakMap<Closure, array<int, array{inject: array<int, ReflectionAttribute<Inject>>, all: array<int, ReflectionAttribute<object>>}>> */
    private WeakMap $closureParameterAttributePlanCache;

    /** @var WeakMap<Closure, array<string, array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}>> */
    private WeakMap $closureResolutionPlanCache;

    /** @var array<string, array<int, ReflectionAttribute<Inject>>> */
    private array $injectCache = [];

    /** @var array<string, array{
     *   inject: array<int, ReflectionAttribute<Inject>>,
     *   all: array<int, ReflectionAttribute<object>>
     * }>
     */
    private array $parameterAttributePlanCache = [];

    /** @var array<string, array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, mixed>
     * }>
     */
    private array $resolutionPlanCache = [];

    public function __construct(
        private readonly Repository $repository,
        private readonly DefinitionResolver $definitionResolver,
    ) {
        $this->closureInjectCache = new WeakMap();
        $this->closureParameterAttributePlanCache = new WeakMap();
        $this->closureResolutionPlanCache = new WeakMap();
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
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push(
                "{$reflector->getShortName()}() params",
                TraceLevelEnum::Verbose,
            );
        }

        $plan = $this->getResolutionPlan($reflector, $type);
        $availableParams = $plan['availableParams'];
        if (!$availableParams) {
            return [];
        }

        $suppliedParameters = $this->normalizeSuppliedParameters($availableParams, $suppliedParameters);
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
        $hasScopeSeeds = $this->repository->hasScopeSeeds();
        $seeded = null;
        if ($hasScopeSeeds && $this->repository->findScopeSeed($name, $seeded)) {
            return $seeded;
        }

        if ($this->repository->hasFunctionReference($name)) {
            return $this->definitionResolver->resolve($name);
        }

        foreach ($this->extractNamedTypeCandidates($parameter) as $named) {
            if ($named->isBuiltin()) {
                continue;
            }
            $typeName = $this->normalizeSelfParent(
                $named->getName(),
                $parameter->getDeclaringClass(),
            );

            if ($hasScopeSeeds && $this->repository->findScopeSeed($typeName, $seeded)) {
                return $seeded;
            }

            if ($this->repository->hasFunctionReference($typeName)) {
                return $this->definitionResolver->resolve($typeName);
            }

            if (!$this->repository->container()->has($typeName)
                && $this->repository->tryResolveMissing($typeName)
                && $this->repository->hasFunctionReference($typeName)
            ) {
                return $this->repository->container()->get($typeName);
            }
        }

        return AttributeResolution::Unresolved;
    }

    /**
     * @param ReflectionClass<object> $dependency
     */
    public function resolveContextualDependency(string $consumer, ReflectionClass $dependency): mixed
    {
        if ($consumer === '') {
            return AttributeResolution::Unresolved;
        }

        if (!$this->repository->hasContextualBinding($consumer, $dependency->getName())) {
            return AttributeResolution::Unresolved;
        }
        $binding = $this->repository->getContextualBinding($consumer, $dependency->getName());

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
        $exists = false;
        foreach ($parameters as $value) {
            $exists = $value instanceof $className;
            if ($exists) {
                break;
            }
        }

        return $exists;
    }

    private function applyEnvOverride(string $fqcn): string
    {
        $concrete = $this->repository->getEnvConcrete($fqcn);
        if ($concrete !== null && class_exists($concrete)) {
            return $concrete;
        }

        return $fqcn;
    }

    /**
     * @return array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}
     */
    private function buildResolutionPlan(ReflectionFunctionAbstract $reflector, string $type): array
    {
        $isMethod = $reflector instanceof ReflectionMethod;
        $applyAttribute = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor $isMethod);

        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes($this->getInjectAttributes($reflector));
        }

        return [
            'availableParams' => $reflector->getParameters(),
            'applyAttribute' => $applyAttribute,
            'attributeData' => $attributeData,
        ];
    }

    private function closureFor(ReflectionFunctionAbstract $reflector): ?Closure
    {
        return $reflector instanceof ReflectionFunction && $reflector->isClosure()
            ? $reflector->getClosure()
            : null;
    }

    /**
     * @template TValue
     * @param array<string, TValue> $cache
     */
    private function evictCacheKeyIfNeeded(array &$cache, string $key, int $limit): void
    {
        if (!isset($cache[$key]) && count($cache) >= $limit) {
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
        $types = [];
        foreach ($this->extractTypeGroups($parameter) as $group) {
            foreach ($group as $candidate) {
                $types[] = $candidate;
            }
        }

        return $types;
    }

    /**
     * Return union alternatives as groups whose members must all be satisfied.
     *
     * @return array<int, array<int, ReflectionNamedType>>
     */
    private function extractTypeGroups(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            return [[$type]];
        }
        if ($type instanceof ReflectionIntersectionType) {
            return [$this->namedIntersectionMembers($type)];
        }
        if (!$type instanceof ReflectionUnionType) {
            return [];
        }

        $groups = [];
        foreach ($type->getTypes() as $candidate) {
            if ($candidate instanceof ReflectionNamedType) {
                $groups[] = [$candidate];

                continue;
            }

            $groups[] = $this->namedIntersectionMembers($candidate);
        }

        return $groups;
    }

    /**
     * @return array<int, ReflectionAttribute<Inject>>
     */
    private function getInjectAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $closure = $this->closureFor($reflector);
        if ($closure instanceof Closure) {
            return $this->closureInjectCache[$closure]
                ??= $reflector->getAttributes(Inject::class);
        }

        $key = $this->reflectorCacheKey($reflector);

        return $this->injectCache[$key] ?? $this->rememberInject(
            $key,
            $reflector->getAttributes(Inject::class),
        );
    }

    /**
     * @return array{
     *   inject: array<int, ReflectionAttribute<Inject>>,
     *   all: array<int, ReflectionAttribute<object>>
     * }
     */
    private function getParameterAttributePlan(ReflectionParameter $parameter): array
    {
        $closure = $this->closureFor($parameter->getDeclaringFunction());
        if ($closure instanceof Closure) {
            $plans = $this->closureParameterAttributePlanCache[$closure] ?? [];
            $position = $parameter->getPosition();
            if (!isset($plans[$position])) {
                $plans[$position] = [
                    'inject' => $parameter->getAttributes(Inject::class),
                    'all' => $parameter->getAttributes(),
                ];
                $this->closureParameterAttributePlanCache[$closure] = $plans;
            }

            return $plans[$position];
        }

        $key = $this->makeParameterAttributePlanKey($parameter);

        return $this->parameterAttributePlanCache[$key] ?? $this->rememberParameterAttributePlan($key, [
            'inject' => $parameter->getAttributes(Inject::class),
            'all' => $parameter->getAttributes(),
        ]);
    }

    /**
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, mixed>
     * }
     */
    private function getResolutionPlan(ReflectionFunctionAbstract $reflector, string $type): array
    {
        $closure = $this->closureFor($reflector);
        if ($closure instanceof Closure) {
            $cacheKey = $type . '|ma:' . ($this->repository->isMethodAttributeEnabled() ? '1' : '0');
            $plans = $this->closureResolutionPlanCache[$closure] ?? [];
            if (isset($plans[$cacheKey])) {
                return $plans[$cacheKey];
            }
            $plans[$cacheKey] = $this->buildResolutionPlan($reflector, $type);
            $this->closureResolutionPlanCache[$closure] = $plans;

            return $plans[$cacheKey];
        }

        $key = $this->makeResolutionPlanKey($reflector, $type);

        return $this->resolutionPlanCache[$key]
            ?? $this->rememberResolutionPlan($key, $this->buildResolutionPlan($reflector, $type));
    }

    private function makeParameterAttributePlanKey(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();

        return $this->reflectorCacheKey($function) . '|p:' . $parameter->getPosition();
    }

    private function makeResolutionPlanKey(ReflectionFunctionAbstract $reflector, string $type): string
    {
        $methodAttrEnabled = $this->repository->isMethodAttributeEnabled() ? '1' : '0';

        return $this->reflectorCacheKey($reflector) . "|$type|ma:$methodAttrEnabled";
    }

    /** @return array<int, ReflectionNamedType> */
    private function namedIntersectionMembers(ReflectionIntersectionType $type): array
    {
        $members = [];
        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType) {
                $members[] = $member;
            }
        }

        return $members;
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

    /**
     * @param array<int, ReflectionParameter> $parameters
     * @param array<int|string, mixed> $supplied
     * @return array<int|string, mixed>
     */
    private function normalizeSuppliedParameters(array $parameters, array $supplied): array
    {
        foreach ($parameters as $position => $parameter) {
            if ($parameter->isVariadic() || !array_key_exists($position, $supplied)) {
                continue;
            }

            if (!array_key_exists($parameter->getName(), $supplied)) {
                $supplied[$parameter->getName()] = $supplied[$position];
            }
            unset($supplied[$position]);
        }

        return $supplied;
    }

    private function ownerFor(ReflectionFunctionAbstract $reflector): string
    {
        return $reflector instanceof ReflectionMethod
            ? $reflector->getDeclaringClass()->getName()
            : '';
    }

    private function reflectorCacheKey(ReflectionFunctionAbstract $reflector): string
    {
        if ($reflector instanceof ReflectionMethod) {
            return $reflector->getDeclaringClass()->getName() . '::' . $reflector->getName();
        }

        if (!$reflector->isClosure()) {
            return $reflector->getName();
        }

        return ($reflector->getFileName() ?: 'unknown')
            . ':' . ($reflector->getStartLine() ?: 0)
            . ':' . ($reflector->getEndLine() ?: 0);
    }

    /**
     * @param array<int, ReflectionAttribute<Inject>> $value
     * @return array<int, ReflectionAttribute<Inject>>
     */
    private function rememberInject(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->injectCache, $key, self::INJECT_CACHE_LIMIT);
        $this->injectCache[$key] = $value;

        return $value;
    }

    /**
     * @param array{
     *   inject: array<int, ReflectionAttribute<Inject>>,
     *   all: array<int, ReflectionAttribute<object>>
     * } $value
     * @return array{
     *   inject: array<int, ReflectionAttribute<Inject>>,
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
     *   attributeData: array<string, mixed>
     * } $value
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   applyAttribute: bool,
     *   attributeData: array<string, mixed>
     * }
     */
    private function rememberResolutionPlan(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->resolutionPlanCache, $key, self::RESOLUTION_PLAN_CACHE_LIMIT);
        $this->resolutionPlanCache[$key] = $value;

        return $value;
    }
}
