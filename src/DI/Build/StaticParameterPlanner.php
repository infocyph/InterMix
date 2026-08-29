<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @internal
 */
final class StaticParameterPlanner
{
    /**
     * @param ReflectionClass<object> $class
     * @return array{arguments: list<ServiceArgument>, dependencies: list<string>}|string
     */
    public function constructorPlan(DefinitionGraph $graph, ReflectionClass $class): array|string
    {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return ['arguments' => [], 'dependencies' => []];
        }

        $arguments = [];
        $dependencies = [];
        $seenDependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $argument = $this->parameterPlan($graph, $class, $parameter);
            if (is_string($argument)) {
                return $argument;
            }
            if ($argument['kind'] === 'service') {
                if (isset($seenDependencies[$argument['id']])) {
                    return "constructor dependency '{$argument['id']}' occurs more than once";
                }
                $seenDependencies[$argument['id']] = true;
                $dependencies[] = $argument['id'];
            }
            $arguments[] = $argument;
        }

        return ['arguments' => $arguments, 'dependencies' => $dependencies];
    }

    private function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn(mixed $item): bool => $this->isExportable($item));
    }

    /** @param ReflectionClass<object> $class */
    private function normalizedDependencyType(ReflectionClass $class, string $type): ?string
    {
        if ($type === 'self') {
            return $class->getName();
        }
        if ($type === 'parent') {
            $parent = $class->getParentClass();

            return $parent instanceof ReflectionClass ? $parent->getName() : null;
        }

        return $type === 'static' ? null : $type;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return ServiceArgument|string
     */
    private function parameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $class,
        ReflectionParameter $parameter,
    ): array|string {
        if ($parameter->isVariadic()) {
            return "constructor parameter '{$parameter->getName()}' is variadic";
        }
        if ($parameter->getAttributes() !== []) {
            return "constructor parameter '{$parameter->getName()}' has attributes";
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return "constructor parameter '{$parameter->getName()}' has a union or intersection type";
        }
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->typedParameterPlan($graph, $class, $parameter, $type);
        }
        if ($parameter->isDefaultValueAvailable() && $this->isExportable($parameter->getDefaultValue())) {
            return ['kind' => 'value', 'code' => var_export($parameter->getDefaultValue(), true)];
        }
        if ($parameter->allowsNull()) {
            return ['kind' => 'value', 'code' => 'null'];
        }

        return "constructor parameter '{$parameter->getName()}' cannot be represented statically";
    }

    /**
     * @param ReflectionClass<object> $class
     * @return ServiceArgument|string
     */
    private function typedParameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $class,
        ReflectionParameter $parameter,
        ReflectionNamedType $type,
    ): array|string {
        $dependency = $this->normalizedDependencyType($class, $type->getName());
        if ($dependency === null) {
            return "constructor parameter '{$parameter->getName()}' has an unsupported relative type";
        }
        if ($graph->hasDefinition($parameter->getName())) {
            return ['kind' => 'service', 'id' => $parameter->getName()];
        }

        return $this->typedServicePlan($graph, $class->getName(), $dependency);
    }

    /** @return array{kind: 'service', id: string}|string */
    private function typedServicePlan(
        DefinitionGraph $graph,
        string $consumer,
        string $dependency,
    ): array|string {
        if (!$graph->hasContextualBinding($consumer, $dependency)) {
            return [
                'kind' => 'service',
                'id' => $graph->environmentConcrete($dependency) ?? $dependency,
            ];
        }

        $binding = $graph->contextualBinding($consumer, $dependency);
        if (!is_string($binding)) {
            return "constructor dependency '$dependency' has a dynamic contextual binding";
        }
        if ($graph->hasDefinition($binding)) {
            return ['kind' => 'service', 'id' => $binding];
        }
        if (!class_exists($binding) && !interface_exists($binding)) {
            return "constructor dependency '$dependency' has a non-service contextual binding";
        }

        return [
            'kind' => 'service',
            'id' => $graph->environmentConcrete($binding) ?? $binding,
        ];
    }
}
