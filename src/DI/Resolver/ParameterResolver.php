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
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class ParameterResolver
{
    use ResolvesAssociativeParameters;
    use ResolvesNumericAndVariadicParameters;
    use ResolvesParameterAttributes;

    private const int INFUSE_CACHE_LIMIT = 1024;
    private const int RESOLUTION_CACHE_LIMIT = 4096;

    private readonly IMStdClass $stdClass;
    private ClassResolver $classResolver;

    /** @var array<string, array> */
    private array $infuseCache = [];

    /** @var array<string, array> */
    private array $resolvedCache = [];

    public function __construct(
        private readonly Repository $repository,
        private readonly DefinitionResolver $definitionResolver,
    ) {
        $this->stdClass = new IMStdClass();
    }

    /**
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type,
    ): array {
        $cacheKey = $this->makeResolutionCacheKey($reflector, $suppliedParameters, $type);
        if (array_key_exists($cacheKey, $this->resolvedCache)) {
            return $this->resolvedCache[$cacheKey];
        }

        $this->repository->tracer()->push(
            "{$reflector->getShortName()}() params",
            TraceLevelEnum::Verbose,
        );

        $availableParams = $reflector->getParameters();
        if (!$availableParams) {
            return $this->rememberResolved($cacheKey, []);
        }

        $applyAttribute = $this->repository->isMethodAttributeEnabled()
            && ($type === 'constructor' xor ($reflector->class ?? null));

        $attributeData = [];
        if ($applyAttribute) {
            $attributeData = $this->resolveMethodAttributes(
                $this->getInfuseAttributes($reflector),
            );
        }

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
            return $this->rememberResolved($cacheKey, $processed);
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

        return $this->rememberResolved($cacheKey, $processed);
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
            default => []
        };

        foreach ($namedTypes as $named) {
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
            is_resource($value) => 'res#' . get_resource_type($value) . '#' . (int)$value,
            is_array($value) => array_map([self::class, 'normalise'], $value),
            default => $value,
        };
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

    private function extractNamedTypeCandidates(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        return match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $type->getTypes(),
            default => []
        };
    }

    private function getInfuseAttributes(ReflectionFunctionAbstract $reflector): array
    {
        $key = ($reflector->class ?? '') . '::' . $reflector->getName();
        if (array_key_exists($key, $this->infuseCache)) {
            return $this->infuseCache[$key];
        }

        return $this->rememberInfuse(
            $key,
            $reflector->getAttributes(Infuse::class),
        );
    }

    /**
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
            $owner = $reflector->class ?? $reflector->getName();
            throw new ContainerException(
                "Multiple instances for {$reflection->getName()} in {$owner}::{$reflector->getShortName()}()",
            );
        }

        return $reflection;
    }

    private function makeResolutionCacheKey(
        ReflectionFunctionAbstract $reflector,
        array $supplied,
        string $type,
    ): string {
        $owner = $reflector->class ?? '';
        $norm = array_map([self::class, 'normalise'], $supplied);
        $argsHash = hash('xxh3', json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return "$owner::{$reflector->getName()}|$type|$argsHash";
    }

    /**
     * @throws ContainerException
     */
    private function normalizeSelfParent(
        string $className,
        ?ReflectionClass $declaring,
    ): string {
        return match ($className) {
            'self' => $declaring?->getName() ?? $className,
            'parent' => $declaring?->getParentClass()?->getName()
                ?? throw new ContainerException("Parameter uses 'parent' but no parent class found."),
            default => $className,
        };
    }

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

    private function rememberBounded(array &$cache, string $key, array $value, int $limit): array
    {
        if (!array_key_exists($key, $cache) && count($cache) >= $limit) {
            $oldest = array_key_first($cache);
            if ($oldest !== null) {
                unset($cache[$oldest]);
            }
        }

        $cache[$key] = $value;
        return $value;
    }

    private function rememberInfuse(string $key, array $value): array
    {
        return $this->rememberBounded($this->infuseCache, $key, $value, self::INFUSE_CACHE_LIMIT);
    }

    private function rememberResolved(string $key, array $value): array
    {
        return $this->rememberBounded($this->resolvedCache, $key, $value, self::RESOLUTION_CACHE_LIMIT);
    }
}
