<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
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
                if (!isset($seenDependencies[$argument['id']])) {
                    $seenDependencies[$argument['id']] = true;
                    $dependencies[] = $argument['id'];
                }
            }
            $arguments[] = $argument;
        }

        return ['arguments' => $arguments, 'dependencies' => $dependencies];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return ServiceArgument|string|null
     */
    private function attributeParameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $class,
        ReflectionParameter $parameter,
    ): array|string|null {
        $attributes = $parameter->getAttributes();
        if ($attributes === []) {
            return null;
        }

        foreach ($attributes as $attribute) {
            $type = $attribute->getName();
            if ($type !== Inject::class && $graph->hasAttributeType($type)) {
                return "constructor parameter '{$parameter->getName()}' has a custom runtime attribute";
            }
        }

        $inject = $parameter->getAttributes(Inject::class)[0] ?? null;
        if ($inject === null || $inject->getArguments() === []) {
            if ($inject !== null && $graph->hasAttributeType(Inject::class)) {
                return "constructor parameter '{$parameter->getName()}' uses a custom Inject resolver";
            }

            return null;
        }

        $target = $inject->newInstance()->getParameterData('type');
        if (!is_string($target) || $target === '' || function_exists($target)) {
            return "constructor parameter '{$parameter->getName()}' has a dynamic #[Inject] target";
        }

        return $this->serviceTargetPlan($graph, $class->getName(), $target);
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

        $attribute = $this->attributeParameterPlan($graph, $class, $parameter);
        if (is_array($attribute) || is_string($attribute)) {
            return $attribute;
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

    /** @return array{kind: 'service', id: string}|string */
    private function serviceTargetPlan(
        DefinitionGraph $graph,
        string $consumer,
        string $target,
    ): array|string {
        if ($graph->hasDefinition($target)) {
            return ['kind' => 'service', 'id' => $target];
        }
        if ($graph->hasContextualBinding($consumer, $target)) {
            return $this->typedServicePlan($graph, $consumer, $target);
        }

        $environment = $graph->environmentConcrete($target);
        if ($environment !== null) {
            return ['kind' => 'service', 'id' => $environment];
        }
        if (class_exists($target)) {
            return ['kind' => 'service', 'id' => $target];
        }

        return "constructor dependency '$target' is not statically resolvable";
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{kind: 'service', id: string}|string
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
            return $this->serviceTargetPlan($graph, $consumer, $dependency);
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
