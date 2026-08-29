<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Closure;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type TypeGroup list<ReflectionNamedType>
 * @phpstan-type DefinitionClass array{known: bool, class: class-string|null}
 * @internal
 */
final class StaticCompoundTypePlanner
{
    /**
     * @param ReflectionClass<object> $consumer
     * @return ServiceArgument|string|null
     */
    public function plan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        ReflectionParameter $parameter,
        ReflectionUnionType|ReflectionIntersectionType $type,
        string $label,
    ): array|string|null {
        $groups = $this->typeGroups($type);
        $contextual = $this->contextualPlan($graph, $consumer, $groups, $label);
        if ($contextual !== null) {
            return $contextual;
        }

        $namedDefinition = $this->definitionPlan(
            $graph,
            $consumer,
            $groups,
            $parameter->getName(),
            $label,
        );
        if ($namedDefinition !== null) {
            return $namedDefinition;
        }

        foreach ($this->flattenGroups($groups) as $named) {
            if ($named->isBuiltin()) {
                continue;
            }
            $dependency = $this->normalizeType($consumer, $named->getName());
            if ($dependency === null || !$graph->hasDefinition($dependency)) {
                continue;
            }

            $definition = $this->definitionPlan($graph, $consumer, $groups, $dependency, $label);
            if ($definition !== null) {
                return $definition;
            }
        }

        return $this->autowirePlan($graph, $consumer, $groups);
    }

    /**
     * @param list<TypeGroup> $groups
     * @return ServiceArgument|null
     */
    private function autowirePlan(DefinitionGraph $graph, ReflectionClass $consumer, array $groups): ?array
    {
        foreach ($groups as $group) {
            foreach ($group as $named) {
                if ($named->isBuiltin()) {
                    continue;
                }
                $dependency = $this->normalizeType($consumer, $named->getName());
                if ($dependency === null) {
                    continue;
                }

                $concrete = $graph->environmentConcrete($dependency) ?? $dependency;
                if (!class_exists($concrete) || !$this->classSatisfiesGroup($consumer, $concrete, $group)) {
                    continue;
                }

                return ['kind' => 'service', 'id' => $concrete];
            }
        }

        return null;
    }

    /**
     * @param list<TypeGroup> $groups
     * @return ServiceArgument|string|null
     */
    private function contextualPlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        array $groups,
        string $label,
    ): array|string|null {
        foreach ($groups as $group) {
            foreach ($group as $named) {
                if ($named->isBuiltin()) {
                    continue;
                }
                $dependency = $this->normalizeType($consumer, $named->getName());
                if ($dependency === null || !$graph->hasContextualBinding($consumer->getName(), $dependency)) {
                    continue;
                }

                $binding = $graph->contextualBinding($consumer->getName(), $dependency);
                if (!is_string($binding)) {
                    return "{$label} dependency '$dependency' has a dynamic contextual binding";
                }

                $candidate = $this->contextualCandidate($graph, $binding);
                if (is_string($candidate)) {
                    return $candidate;
                }
                if ($candidate === null) {
                    continue;
                }
                if ($this->classSatisfiesAnyGroup($consumer, $candidate['class'], $groups)) {
                    return ['kind' => 'service', 'id' => $candidate['id']];
                }
            }
        }

        return null;
    }

    /** @return array{id: string, class: class-string}|string|null */
    private function contextualCandidate(DefinitionGraph $graph, string $binding): array|string|null
    {
        if ($graph->hasDefinition($binding)) {
            $resolved = $this->definitionClass($graph, $binding);
            if (!$resolved['known']) {
                return "contextual service '$binding' has an unknown runtime type";
            }

            return $resolved['class'] === null
                ? null
                : ['id' => $binding, 'class' => $resolved['class']];
        }
        if (!class_exists($binding) && !interface_exists($binding)) {
            return null;
        }

        $concrete = $graph->environmentConcrete($binding) ?? $binding;
        if (!class_exists($concrete)) {
            return "contextual type '$binding' does not resolve to an instantiable class";
        }

        return ['id' => $concrete, 'class' => $concrete];
    }

    /**
     * @param list<TypeGroup> $groups
     * @return ServiceArgument|string|null
     */
    private function definitionPlan(
        DefinitionGraph $graph,
        ReflectionClass $consumer,
        array $groups,
        string $id,
        string $label,
    ): array|string|null {
        if (!$graph->hasDefinition($id)) {
            return null;
        }

        $resolved = $this->definitionClass($graph, $id);
        if (!$resolved['known']) {
            return "{$label} definition '$id' has an unknown runtime type";
        }
        if ($resolved['class'] === null
            || !$this->classSatisfiesAnyGroup($consumer, $resolved['class'], $groups)
        ) {
            return null;
        }

        return ['kind' => 'service', 'id' => $id];
    }

    /** @return DefinitionClass */
    private function definitionClass(DefinitionGraph $graph, string $id, array $seen = []): array
    {
        if (isset($seen[$id])) {
            return ['known' => false, 'class' => null];
        }
        $seen[$id] = true;
        $definition = $graph->definitions()[$id] ?? null;

        if ($definition instanceof AliasDefinition) {
            return $this->definitionClass($graph, $definition->target, $seen);
        }
        if ($definition instanceof FactoryDefinition || $definition instanceof Closure) {
            return ['known' => false, 'class' => null];
        }
        if (is_string($definition)) {
            return class_exists($definition)
                ? ['known' => true, 'class' => $definition]
                : ['known' => true, 'class' => null];
        }
        if (is_array($definition)) {
            $class = $definition[0] ?? null;
            $method = $definition[1] ?? null;
            if (is_string($class) && class_exists($class) && !is_string($method)) {
                return ['known' => true, 'class' => $class];
            }

            return is_string($method)
                ? ['known' => false, 'class' => null]
                : ['known' => true, 'class' => null];
        }
        if (is_object($definition)) {
            return ['known' => true, 'class' => $definition::class];
        }

        return ['known' => true, 'class' => null];
    }

    /**
     * @param class-string $class
     * @param list<TypeGroup> $groups
     */
    private function classSatisfiesAnyGroup(ReflectionClass $consumer, string $class, array $groups): bool
    {
        return array_any(
            $groups,
            fn(array $group): bool => $this->classSatisfiesGroup($consumer, $class, $group),
        );
    }

    /**
     * @param class-string $class
     * @param TypeGroup $group
     */
    private function classSatisfiesGroup(ReflectionClass $consumer, string $class, array $group): bool
    {
        foreach ($group as $named) {
            if ($named->isBuiltin()) {
                return false;
            }
            $required = $this->normalizeType($consumer, $named->getName());
            if ($required === null || !is_a($class, $required, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<TypeGroup> $groups @return list<ReflectionNamedType> */
    private function flattenGroups(array $groups): array
    {
        $types = [];
        foreach ($groups as $group) {
            foreach ($group as $named) {
                $types[] = $named;
            }
        }

        return $types;
    }

    /** @param ReflectionClass<object> $consumer */
    private function normalizeType(ReflectionClass $consumer, string $type): ?string
    {
        if ($type === 'self') {
            return $consumer->getName();
        }
        if ($type === 'parent') {
            $parent = $consumer->getParentClass();

            return $parent instanceof ReflectionClass ? $parent->getName() : null;
        }

        return $type === 'static' ? null : $type;
    }

    /** @return list<ReflectionNamedType> */
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

    /** @return list<TypeGroup> */
    private function typeGroups(ReflectionUnionType|ReflectionIntersectionType $type): array
    {
        if ($type instanceof ReflectionIntersectionType) {
            return [$this->namedIntersectionMembers($type)];
        }

        $groups = [];
        foreach ($type->getTypes() as $candidate) {
            $groups[] = $candidate instanceof ReflectionNamedType
                ? [$candidate]
                : $this->namedIntersectionMembers($candidate);
        }

        return $groups;
    }
}
