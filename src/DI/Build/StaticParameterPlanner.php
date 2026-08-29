<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use ReflectionClass;
use ReflectionFunctionAbstract;
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
     * @param ReflectionClass<object> $consumer
     * @param array<int|string, mixed> $supplied
     * @param array<string, ServiceArgument> $attributeArguments
     * @return array{arguments: list<ServiceArgument>, dependencies: list<string>}|string
     */
    public function callablePlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        ReflectionFunctionAbstract $reflector,
        array $supplied = [],
        string $label = 'parameter',
        bool $rejectAttributes = false,
        array $attributeArguments = [],
    ): array|string {
        $parameters = $reflector->getParameters();
        $supplied = $this->normalizeSuppliedParameters($parameters, $supplied);
        $arguments = [];
        $dependencies = [];
        $seenDependencies = [];

        foreach ($parameters as $parameter) {
            $argument = $this->parameterPlan(
                $graph,
                $consumer,
                $parameter,
                $supplied,
                $label,
                $rejectAttributes,
                $attributeArguments,
            );
            if (is_string($argument)) {
                return $argument;
            }
            if ($argument['kind'] === 'service' && !isset($seenDependencies[$argument['id']])) {
                $seenDependencies[$argument['id']] = true;
                $dependencies[] = $argument['id'];
            }
            $arguments[] = $argument;
        }

        return ['arguments' => $arguments, 'dependencies' => $dependencies];
    }

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

        return $this->callablePlan(
            $graph,
            $class,
            $constructor,
            $this->resourceParameters($graph, $class->getName(), 'constructor'),
            'constructor',
            true,
        );
    }

    /** @return array{kind: 'service', id: string}|string|null */
    private function attributeParameterPlan(
        DefinitionGraph $graph,
        ReflectionParameter $parameter,
        bool $rejectAttributes,
    ): array|string|null {
        if ($rejectAttributes || !$graph->methodAttributesEnabled()) {
            return null;
        }

        return new StaticInjectPlanner()->parameterArgument($graph, $parameter);
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

    /**
     * @param ReflectionClass<object> $consumer
     * @param array<int|string, mixed> $supplied
     * @param array<string, ServiceArgument> $attributeArguments
     * @return ServiceArgument|string
     */
    private function parameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        ReflectionParameter $parameter,
        array $supplied,
        string $label,
        bool $rejectAttributes,
        array $attributeArguments,
    ): array|string {
        $name = $parameter->getName();
        if ($parameter->isVariadic()) {
            return "{$label} parameter '{$name}' is variadic";
        }

        $suppliedPlan = $this->suppliedParameterPlan($parameter, $supplied, $label);
        if ($suppliedPlan !== null) {
            return $suppliedPlan;
        }
        if ($rejectAttributes && $parameter->getAttributes() !== []) {
            return "{$label} parameter '{$name}' has attributes";
        }

        $untypedDefinition = $this->untypedDefinitionPlan($graph, $parameter);
        if ($untypedDefinition !== null) {
            return $untypedDefinition;
        }

        $typePlan = $this->typeParameterPlan($graph, $consumer, $parameter, $label);
        if ($typePlan !== null) {
            return $typePlan;
        }
        if (isset($attributeArguments[$name])) {
            return $attributeArguments[$name];
        }

        $attributePlan = $this->attributeParameterPlan($graph, $parameter, $rejectAttributes);
        if ($attributePlan !== null) {
            return $attributePlan;
        }
        if ($parameter->isDefaultValueAvailable() && $this->isExportable($parameter->getDefaultValue())) {
            return ['kind' => 'value', 'code' => var_export($parameter->getDefaultValue(), true)];
        }
        if ($parameter->allowsNull()) {
            return ['kind' => 'value', 'code' => 'null'];
        }

        return "{$label} parameter '{$name}' cannot be represented statically";
    }

    /** @return array<int|string, mixed> */
    private function resourceParameters(DefinitionGraph $graph, string $class, string $scope): array
    {
        $resource = $graph->classResourcesFor($class)[$scope] ?? null;
        if (!is_array($resource)) {
            return [];
        }

        $parameters = $resource['params'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }

    /**
     * @param array<int|string, mixed> $supplied
     * @return array{kind: 'value', code: string}|string|null
     */
    private function suppliedParameterPlan(
        ReflectionParameter $parameter,
        array $supplied,
        string $label,
    ): array|string|null {
        $name = $parameter->getName();
        if (!array_key_exists($name, $supplied)) {
            return null;
        }
        if (!$this->isExportable($supplied[$name])) {
            return "{$label} parameter '{$name}' has a non-exportable supplied value";
        }

        return ['kind' => 'value', 'code' => var_export($supplied[$name], true)];
    }

    /**
     * @param ReflectionClass<object> $consumer
     * @return array{kind: 'service', id: string}|string
     */
    private function typedParameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        ReflectionParameter $parameter,
        ReflectionNamedType $type,
        string $label,
    ): array|string {
        $dependency = $this->normalizedDependencyType($consumer, $type->getName());
        if ($dependency === null) {
            return "{$label} parameter '{$parameter->getName()}' has an unsupported relative type";
        }
        if ($graph->hasContextualBinding($consumer->getName(), $dependency)) {
            return $this->typedServicePlan($graph, $consumer->getName(), $dependency, $label);
        }
        if ($graph->hasDefinition($parameter->getName())) {
            return ['kind' => 'service', 'id' => $parameter->getName()];
        }

        return $this->typedServicePlan($graph, $consumer->getName(), $dependency, $label);
    }

    /** @return array{kind: 'service', id: string}|string */
    private function typedServicePlan(
        DefinitionGraph $graph,
        string $consumer,
        string $dependency,
        string $label,
    ): array|string {
        if (!$graph->hasContextualBinding($consumer, $dependency)) {
            return [
                'kind' => 'service',
                'id' => $graph->environmentConcrete($dependency) ?? $dependency,
            ];
        }

        $binding = $graph->contextualBinding($consumer, $dependency);
        if (!is_string($binding)) {
            return "{$label} dependency '$dependency' has a dynamic contextual binding";
        }
        if ($graph->hasDefinition($binding)) {
            return ['kind' => 'service', 'id' => $binding];
        }
        if (!class_exists($binding) && !interface_exists($binding)) {
            return "{$label} dependency '$dependency' has a non-service contextual binding";
        }

        return [
            'kind' => 'service',
            'id' => $graph->environmentConcrete($binding) ?? $binding,
        ];
    }

    /**
     * @param ReflectionClass<object> $consumer
     * @return array{kind: 'service', id: string}|string|null
     */
    private function typeParameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        ReflectionParameter $parameter,
        string $label,
    ): array|string|null {
        $type = $parameter->getType();
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return new StaticCompoundTypePlanner()->plan(
                $graph,
                $consumer,
                $parameter,
                $type,
                $label,
            );
        }
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->typedParameterPlan($graph, $consumer, $parameter, $type, $label);
        }

        return null;
    }

    /** @return array{kind: 'service', id: string}|null */
    private function untypedDefinitionPlan(DefinitionGraph $graph, ReflectionParameter $parameter): ?array
    {
        if ($parameter->getType() !== null || !$graph->hasDefinition($parameter->getName())) {
            return null;
        }

        return ['kind' => 'service', 'id' => $parameter->getName()];
    }
}
