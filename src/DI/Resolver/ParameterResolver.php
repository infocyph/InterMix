<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesAssociativeParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesNumericAndVariadicParameters;
use Infocyph\InterMix\DI\Resolver\Concerns\ResolvesParameterAttributes;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class ParameterResolver
{
    use ResolvesAssociativeParameters;
    use ResolvesNumericAndVariadicParameters;
    use ResolvesParameterAttributes;

    private const int INJECT_CACHE_LIMIT = 1024;

    private const int PARAM_ATTRIBUTE_PLAN_CACHE_LIMIT = 4096;

    private const int RESOLUTION_PLAN_CACHE_LIMIT = 2048;

    private const int TYPE_GROUP_CACHE_LIMIT = 4096;

    private ClassResolver $classResolver;

    /** @var array<string, array<int, ReflectionAttribute<Inject>>> */
    private array $injectCache = [];

    /** @var array<string, array{inject: array<int, ReflectionAttribute<Inject>>, all: array<int, ReflectionAttribute<object>>}> */
    private array $parameterAttributePlanCache = [];

    /** @var array<string, array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}> */
    private array $resolutionPlanCache = [];

    /** @var array<string, array<int, array<int, ReflectionNamedType>>> */
    private array $typeGroupCache = [];

    public function __construct(private readonly Repository $repository) {}

    /**
     * @param array<int|string, mixed> $suppliedParameters
     * @return array<int|string, mixed>
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
        if ($availableParams === []) {
            return [];
        }

        $suppliedParameters = $this->normalizeSuppliedParameters($availableParams, $suppliedParameters);

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
            $plan['attributeData'],
        );
        if ($paramsLeft === []) {
            return $processed;
        }

        [
            'processed' => $numProcessed,
            'variadic' => $variadic,
        ] = $this->resolveNumericDefaultParameters(
            $reflector,
            $paramsLeft,
            $availableSupply,
            $plan['applyAttribute'],
        );
        $processed += $numProcessed;

        return $variadic['value'] !== null
            ? $this->processVariadic($processed, $variadic, $sort)
            : $processed;
    }

    public function resolveByDefinitionType(string $name, ReflectionParameter $parameter): mixed
    {
        $hasScopeSeeds = $this->repository->hasScopeSeeds();
        $seeded = null;
        if ($hasScopeSeeds && $this->repository->findScopeSeed($name, $seeded)) {
            return $seeded;
        }

        $container = $this->repository->container();
        if ($this->repository->hasFunctionReference($name)) {
            return $container->get($name);
        }

        foreach ($this->extractNamedTypeCandidates($parameter) as $named) {
            if ($named->isBuiltin()) {
                continue;
            }

            $typeName = $this->normalizeSelfParent(
                $named->getName(),
                $parameter->getDeclaringClass(),
            );
            $resolved = $this->resolveNamedDefinitionType($typeName, $hasScopeSeeds, $seeded);
            if ($resolved !== AttributeResolution::Unresolved) {
                return $resolved;
            }
        }

        return AttributeResolution::Unresolved;
    }

    /**
     * @param ReflectionClass<object> $dependency
     */
    public function resolveContextualDependency(string $consumer, ReflectionClass $dependency): mixed
    {
        if ($consumer === ''
            || !$this->repository->hasContextualBinding($consumer, $dependency->getName())
        ) {
            return AttributeResolution::Unresolved;
        }

        $binding = $this->repository->getContextualBinding($consumer, $dependency->getName());

        if (is_callable($binding)) {
            return $binding($this->repository->container());
        }

        if (is_string($binding)) {
            if ($this->repository->hasFunctionReference($binding)) {
                return $this->repository->container()->get($binding);
            }

            if (class_exists($binding) || interface_exists($binding)) {
                return $this->classResolver->resolveClassInstance(
                    ReflectionResource::getClassReflection($this->applyEnvOverride($binding)),
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
        $concrete = $this->repository->getEnvConcrete($fqcn);

        return $concrete !== null && class_exists($concrete) ? $concrete : $fqcn;
    }

    /**
     * @return array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}
     */
    private function buildResolutionPlan(ReflectionFunctionAbstract $reflector, string $type): array
    {
        $availableParams = $reflector->getParameters();

        foreach ($availableParams as $parameter) {
            $this->extractTypeGroups($parameter);
        }

        $isMethod = $reflector instanceof ReflectionMethod;
        $applyAttribute = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor $isMethod);

        return [
            'availableParams' => $availableParams,
            'applyAttribute' => $applyAttribute,
            'attributeData' => $applyAttribute
                ? $this->resolveMethodAttributes($this->getInjectAttributes($reflector))
                : [],
        ];
    }

    /**
     * @return array<int, array<int, ReflectionNamedType>>
     */
    private function buildTypeGroups(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        return match (true) {
            $type instanceof ReflectionNamedType => [[$type]],
            $type instanceof ReflectionIntersectionType => [$this->namedIntersectionMembers($type)],
            !$type instanceof ReflectionUnionType => [],
            default => $this->unionTypeGroups($type),
        };
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
     * @return array<int, array<int, ReflectionNamedType>>
     */
    private function extractTypeGroups(ReflectionParameter $parameter): array
    {
        $declaring = $parameter->getDeclaringFunction();
        if ($declaring->isClosure()) {
            return $this->buildTypeGroups($parameter);
        }

        $key = $this->makeParameterTypeGroupKey($parameter);
        if (isset($this->typeGroupCache[$key])) {
            return $this->typeGroupCache[$key];
        }

        $groups = $this->buildTypeGroups($parameter);
        $this->evictCacheKeyIfNeeded($this->typeGroupCache, $key, self::TYPE_GROUP_CACHE_LIMIT);
        $this->typeGroupCache[$key] = $groups;

        return $groups;
    }

    /**
     * @return array<int, ReflectionAttribute<Inject>>
     */
    private function getInjectAttributes(ReflectionFunctionAbstract $reflector): array
    {
        if ($reflector->isClosure()) {
            return $reflector->getAttributes(Inject::class);
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
        if ($parameter->getDeclaringFunction()->isClosure()) {
            return [
                'inject' => $parameter->getAttributes(Inject::class),
                'all' => $parameter->getAttributes(),
            ];
        }

        $key = $this->makeParameterAttributePlanKey($parameter);

        return $this->parameterAttributePlanCache[$key] ?? $this->rememberParameterAttributePlan($key, [
            'inject' => $parameter->getAttributes(Inject::class),
            'all' => $parameter->getAttributes(),
        ]);
    }

    /**
     * @return array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}
     */
    private function getResolutionPlan(ReflectionFunctionAbstract $reflector, string $type): array
    {
        if ($reflector->isClosure()) {
            return $this->buildResolutionPlan($reflector, $type);
        }

        $key = $this->makeResolutionPlanKey($reflector, $type);

        return $this->resolutionPlanCache[$key]
            ?? $this->rememberResolutionPlan($key, $this->buildResolutionPlan($reflector, $type));
    }

    private function makeParameterAttributePlanKey(ReflectionParameter $parameter): string
    {
        return $this->reflectorCacheKey($parameter->getDeclaringFunction())
            . '|p:' . $parameter->getPosition();
    }

    private function makeParameterTypeGroupKey(ReflectionParameter $parameter): string
    {
        return $this->reflectorCacheKey($parameter->getDeclaringFunction())
            . '|t:' . $parameter->getPosition();
    }

    private function makeResolutionPlanKey(ReflectionFunctionAbstract $reflector, string $type): string
    {
        return $this->reflectorCacheKey($reflector)
            . "|$type|ma:"
            . ($this->repository->isMethodAttributeEnabled() ? '1' : '0');
    }

    /**
     * @return array<int, ReflectionNamedType>
     */
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
     */
    private function normalizeSelfParent(string $className, ?ReflectionClass $declaring): string
    {
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

        return $reflector->getName();
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
        $this->evictCacheKeyIfNeeded(
            $this->parameterAttributePlanCache,
            $key,
            self::PARAM_ATTRIBUTE_PLAN_CACHE_LIMIT,
        );
        $this->parameterAttributePlanCache[$key] = $value;

        return $value;
    }

    /**
     * @param array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>} $value
     * @return array{availableParams: array<int, ReflectionParameter>, applyAttribute: bool, attributeData: array<string, mixed>}
     */
    private function rememberResolutionPlan(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->resolutionPlanCache, $key, self::RESOLUTION_PLAN_CACHE_LIMIT);
        $this->resolutionPlanCache[$key] = $value;

        return $value;
    }

    private function resolveNamedDefinitionType(string $name, bool $hasScopeSeeds, mixed &$seeded): mixed
    {
        if ($hasScopeSeeds && $this->repository->findScopeSeed($name, $seeded)) {
            return $seeded;
        }

        $container = $this->repository->container();
        if ($this->repository->hasFunctionReference($name)) {
            return $container->get($name);
        }

        if (!$this->repository->hasMissingHooks()
            || $container->has($name)
            || !$this->repository->tryResolveMissing($name)
            || !$this->repository->hasFunctionReference($name)
        ) {
            return AttributeResolution::Unresolved;
        }

        return $container->get($name);
    }

    /**
     * @return array<int, array<int, ReflectionNamedType>>
     */
    private function unionTypeGroups(ReflectionUnionType $type): array
    {
        $groups = [];
        foreach ($type->getTypes() as $candidate) {
            $groups[] = $candidate instanceof ReflectionNamedType
                ? [$candidate]
                : $this->namedIntersectionMembers($candidate);
        }

        return $groups;
    }
}
