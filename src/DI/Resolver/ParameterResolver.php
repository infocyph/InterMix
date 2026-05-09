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

    private const int RESOLUTION_CACHE_LIMIT = 4096;

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

    /** @var array<string, array<int|string, mixed>> */
    private array $resolvedCache = [];

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
        $useValueCache = $this->shouldUseValueCache($reflector, $type);
        $cacheKey = null;

        if ($useValueCache) {
            $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
            if (array_key_exists($cacheKey, $this->resolvedCache)) {
                return $this->resolvedCache[$cacheKey];
            }
        }

        $this->repository->tracer()->push(
            "{$reflector->getShortName()}() params",
            TraceLevelEnum::Verbose,
        );

        $plan = $this->getResolutionPlan($reflector, $type);
        $availableParams = $plan['availableParams'];
        if (!$availableParams) {
            return $useValueCache ? $this->rememberResolved((string) $cacheKey, []) : [];
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
            return $useValueCache ? $this->rememberResolved((string) $cacheKey, $processed) : $processed;
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

        return $useValueCache ? $this->rememberResolved((string) $cacheKey, $processed) : $processed;
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

            if (class_exists($typeName) || interface_exists($typeName)) {
                $ref = ReflectionResource::getClassReflection($typeName);

                return $this->classResolver->resolve($ref)['instance'];
            }
        }

        return $this->stdClass;
    }

    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \Closure => 'closure#' . spl_object_id($value),
            is_object($value) => 'obj#' . spl_object_id($value),
            is_resource($value) => 'res#' . get_resource_type($value) . '#' . (int) $value,
            is_array($value) => array_map([self::class, 'normalise'], $value),
            default => $value,
        };
    }

    private static function stableHash(string $value): string
    {
        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'xxh3';

        return hash($algorithm, $value);
    }

    /**
     * @param array<int|string, mixed> $parameters
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

    /**
     * @param array<int|string, mixed> $supplied
     */
    private function makeFastScalarFingerprint(array $supplied): ?string
    {
        if (count($supplied) > 16) {
            return null;
        }

        $parts = [];
        foreach ($supplied as $key => $value) {
            if (is_array($value) || is_object($value) || is_resource($value)) {
                return null;
            }

            $parts[] = $key . '=' . match (true) {
                $value === null => 'n:null',
                is_bool($value) => 'b:' . ($value ? '1' : '0'),
                is_int($value) => 'i:' . $value,
                is_float($value) => 'f:' . $value,
                default => 's:' . base64_encode(is_string($value) ? $value : var_export($value, true)),
            };
        }

        return 'f:' . implode('|', $parts);
    }

    private function makeParameterAttributePlanKey(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();
        $owner = $this->ownerFor($function);
        $signature = ReflectionResource::getSignature($function);

        return $owner . '::' . $function->getName() . '|p:' . $parameter->getPosition() . '|sig:' . $signature;
    }

    /**
     * @param array<int|string, mixed> $supplied
     */
    private function makeResolutionCacheKey(
        ReflectionFunctionAbstract $reflector,
        array $supplied,
        string $type,
    ): string {
        $owner = $this->ownerFor($reflector);
        if ($supplied === []) {
            return "$owner::{$reflector->getName()}|$type|empty";
        }

        $fast = $this->makeFastScalarFingerprint($supplied);
        if ($fast !== null) {
            return "$owner::{$reflector->getName()}|$type|$fast";
        }

        $norm = array_map([self::class, 'normalise'], $supplied);
        $argsHash = self::stableHash(json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return "$owner::{$reflector->getName()}|$type|h:$argsHash";
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

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private function rememberResolved(string $key, array $value): array
    {
        $this->evictCacheKeyIfNeeded($this->resolvedCache, $key, self::RESOLUTION_CACHE_LIMIT);
        $this->resolvedCache[$key] = $value;

        return $value;
    }

    private function shouldUseValueCache(ReflectionFunctionAbstract $reflector, string $type): bool
    {
        return $type === 'constructor'
            && $reflector instanceof ReflectionMethod
            && $reflector->getName() === '__construct';
    }
}
