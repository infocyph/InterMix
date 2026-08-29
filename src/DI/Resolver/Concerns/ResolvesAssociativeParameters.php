<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

trait ResolvesAssociativeParameters
{
    /**
     * @param array<int, ReflectionParameter> $availableParams
     * @param array<int|string, mixed> $suppliedParameters
     * @param array<string, mixed> $parameterAttribute
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   processed: array<string, mixed>,
     *   availableSupply: array<int|string, mixed>,
     *   sort: array<string, int>
     * }
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
    ): array {
        $processed = [];
        $paramsLeft = [];
        $sort = [];
        $availableSupply = $suppliedParameters;

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
                $processed,
            );

            if ($resolvedValue !== AttributeResolution::Unresolved) {
                $processed[$paramName] = $resolvedValue;
                unset($availableSupply[$paramName]);

                continue;
            }

            $paramsLeft[] = $param;
        }

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => $availableSupply,
            'sort' => $sort,
        ];
    }

    /**
     * @param array<int|string, mixed> $processed
     * @param array<int, array<int, \ReflectionNamedType>> $groups
     */
    private function resolveAutowireGroups(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $parameter,
        string $type,
        array $processed,
        array $groups,
    ): mixed {
        $declaring = $parameter->getDeclaringClass();
        foreach ($groups as $group) {
            foreach ($group as $named) {
                if ($named->isBuiltin()) {
                    continue;
                }

                $name = $this->applyEnvOverride(
                    $this->normalizeSelfParent($named->getName(), $declaring),
                );
                if (!class_exists($name)) {
                    continue;
                }

                $reflection = ReflectionResource::getClassReflection($name);
                if ($type === 'constructor' && $declaring?->getName() === $reflection->getName()) {
                    throw new ContainerException("Circular dependency on {$reflection->getName()}");
                }
                if ($this->alreadyExist($reflection->getName(), $processed)) {
                    throw new ContainerException(
                        "Multiple instances for {$reflection->getName()} in "
                        . $this->ownerFor($reflector)
                        . "::{$reflector->getShortName()}()",
                    );
                }

                $resolved = $this->resolveClassDependency($reflection);
                if ($this->satisfiesTypeGroup($resolved, $group, $declaring)) {
                    return $resolved;
                }
            }
        }

        return AttributeResolution::Unresolved;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function resolveClassDependency(ReflectionClass $class): object
    {
        return $this->classResolver->resolveClassInstance($class);
    }

    /**
     * @param array<int, array<int, \ReflectionNamedType>> $groups
     */
    private function resolveContextualGroups(ReflectionFunctionAbstract $reflector, array $groups): mixed
    {
        if ($groups === [] || !$this->repository->hasContextualBindings()) {
            return AttributeResolution::Unresolved;
        }

        $declaring = $reflector instanceof ReflectionMethod
            ? $reflector->getDeclaringClass()
            : null;
        $consumer = $this->ownerFor($reflector);
        if ($consumer === '') {
            return AttributeResolution::Unresolved;
        }

        foreach ($groups as $group) {
            foreach ($group as $named) {
                if ($named->isBuiltin()) {
                    continue;
                }

                $name = $this->normalizeSelfParent($named->getName(), $declaring);
                if (!$this->repository->hasContextualBinding($consumer, $name)) {
                    continue;
                }

                $resolved = $this->resolveContextualDependency(
                    $consumer,
                    ReflectionResource::getClassReflection($name),
                );
                if ($resolved !== AttributeResolution::Unresolved
                    && $this->satisfiesAnyTypeGroupsForDeclaring($resolved, $groups, $declaring)
                ) {
                    return $resolved;
                }
            }
        }

        return AttributeResolution::Unresolved;
    }

    private function resolveIndividualAttribute(
        ReflectionParameter $param,
        mixed $attributeValue,
    ): mixed {
        if (!is_string($attributeValue)) {
            return $attributeValue;
        }

        $definition = $this->resolveByDefinitionType($attributeValue, $param);
        if ($definition !== AttributeResolution::Unresolved) {
            return $definition;
        }

        if (function_exists($attributeValue)) {
            $reflectionFn = ReflectionResource::getFunctionReflection($attributeValue);

            return $attributeValue(...$this->resolve($reflectionFn, [], 'constructor'));
        }

        return $attributeValue;
    }

    /**
     * @param array<int, ReflectionAttribute<\Infocyph\InterMix\DI\Attribute\Inject>> $attributes
     * @return array<string, mixed>
     */
    private function resolveMethodAttributes(array $attributes): array
    {
        $first = $attributes[0] ?? null;
        if ($first === null || $first->getArguments() === []) {
            return [];
        }

        $arguments = $first->newInstance()->getMethodArguments();
        if (!is_array($arguments)) {
            return [];
        }

        $normalized = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array<int, \ReflectionNamedType>> $groups
     */
    private function satisfiesAnyTypeGroup(mixed $value, array $groups, ReflectionParameter $parameter): bool
    {
        if ($groups === []) {
            return true;
        }

        $declaring = $parameter->getDeclaringClass();
        foreach ($groups as $group) {
            if ($this->satisfiesTypeGroup($value, $group, $declaring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, \ReflectionNamedType>> $groups
     * @param ReflectionClass<object>|null $declaring
     */
    private function satisfiesAnyTypeGroupsForDeclaring(
        mixed $value,
        array $groups,
        ?ReflectionClass $declaring,
    ): bool {
        foreach ($groups as $group) {
            if ($this->satisfiesTypeGroup($value, $group, $declaring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, \ReflectionNamedType> $group
     * @param ReflectionClass<object>|null $declaring
     */
    private function satisfiesTypeGroup(mixed $value, array $group, ?ReflectionClass $declaring): bool
    {
        foreach ($group as $named) {
            if ($named->isBuiltin()) {
                if ($named->getName() === 'null' && $value === null) {
                    continue;
                }

                return false;
            }

            $name = $this->normalizeSelfParent($named->getName(), $declaring);
            if (!is_object($value) || !$value instanceof $name) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $suppliedParameters
     * @param array<string, mixed> $parameterAttribute
     * @param array<string, mixed> $processed
     */
    private function tryResolveAssociative(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $param,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
        array $processed,
    ): mixed {
        $paramName = $param->getName();
        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        $groups = $this->extractTypeGroups($param);
        if ($groups !== []) {
            $contextual = $this->resolveContextualGroups($reflector, $groups);
            if ($contextual !== AttributeResolution::Unresolved) {
                return $contextual;
            }
        }

        $definition = $this->resolveByDefinitionType($paramName, $param);
        if ($definition !== AttributeResolution::Unresolved
            && $this->satisfiesAnyTypeGroup($definition, $groups, $param)
        ) {
            return $definition;
        }

        if (array_key_exists($paramName, $parameterAttribute)) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== AttributeResolution::Unresolved) {
                return $resolved;
            }
        }

        return $groups === []
            ? AttributeResolution::Unresolved
            : $this->resolveAutowireGroups($reflector, $param, $type, $processed, $groups);
    }
}
