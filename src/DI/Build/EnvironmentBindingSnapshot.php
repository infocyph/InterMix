<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** @internal */
final class EnvironmentBindingSnapshot
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, array<string, mixed>> $contextualBindings
     * @return array<string, string>
     */
    public function capture(
        Repository $repository,
        array $definitions,
        array $contextualBindings,
    ): array {
        if ($repository->getEnvironment() === null) {
            return [];
        }

        $pending = $this->seedTypes($definitions, $contextualBindings);
        $bindings = [];
        $seen = [];
        while (($type = array_pop($pending)) !== null) {
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $type = $this->resolveEnvironmentType($repository, $type, $pending, $bindings);
            $this->appendConstructorTypes($repository, $type, $pending, $bindings);
        }
        ksort($bindings, SORT_STRING);

        return $bindings;
    }

    /**
     * @param list<string> $pending
     * @param array<string, string> $bindings
     */
    private function appendConstructorTypes(
        Repository $repository,
        string $type,
        array &$pending,
        array &$bindings,
    ): void {
        if (!class_exists($type)) {
            return;
        }

        $constructor = ReflectionResource::getClassReflection($type)->getConstructor();
        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            foreach ($this->namedTypes($parameter->getType()) as $parameterType) {
                if ($parameterType->isBuiltin()) {
                    continue;
                }
                $this->appendDependency($repository, $parameterType->getName(), $pending, $bindings);
            }
        }
    }

    /**
     * @param list<string> $pending
     * @param array<string, string> $bindings
     */
    private function appendDependency(
        Repository $repository,
        string $dependency,
        array &$pending,
        array &$bindings,
    ): void {
        $concrete = $repository->getEnvConcrete($dependency);
        if ($concrete !== null && class_exists($concrete)) {
            $bindings[$dependency] = $concrete;
            $pending[] = $concrete;

            return;
        }
        if (class_exists($dependency)) {
            $pending[] = $dependency;
        }
    }

    /** @return list<ReflectionNamedType> */
    private function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }
        if (!$type instanceof ReflectionUnionType && !$type instanceof ReflectionIntersectionType) {
            return [];
        }

        $types = [];
        foreach ($type->getTypes() as $member) {
            foreach ($this->namedTypes($member) as $named) {
                $types[] = $named;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $pending
     * @param array<string, string> $bindings
     */
    private function resolveEnvironmentType(
        Repository $repository,
        string $type,
        array &$pending,
        array &$bindings,
    ): string {
        $concrete = $repository->getEnvConcrete($type);
        if ($concrete === null || !class_exists($concrete)) {
            return $type;
        }

        $bindings[$type] = $concrete;
        $pending[] = $concrete;

        return $concrete;
    }

    /**
     * @param array<string, mixed> $definitions
     * @param array<string, array<string, mixed>> $contextualBindings
     * @return list<string>
     */
    private function seedTypes(array $definitions, array $contextualBindings): array
    {
        $types = [];
        foreach ($definitions as $definition) {
            if (is_string($definition) && class_exists($definition)) {
                $types[] = $definition;
            }
        }
        foreach ($contextualBindings as $bindings) {
            foreach ($bindings as $binding) {
                if (is_string($binding) && (class_exists($binding) || interface_exists($binding))) {
                    $types[] = $binding;
                }
            }
        }

        return $types;
    }
}
