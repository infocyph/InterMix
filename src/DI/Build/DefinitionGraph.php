<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionNamedType;

/**
 * Immutable build-time snapshot of resolution-affecting container state.
 *
 * The graph deliberately owns copies of mutable repository metadata so future
 * compiler work can operate without retaining the live runtime repository.
 *
 * @internal
 */
final readonly class DefinitionGraph
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}> $definitionMeta
     * @param array<string, array<string, mixed>> $classResources
     * @param array<string, array{on: callable, params: array<int|string, mixed>}> $closureResources
     * @param array<string, array<string, mixed>> $contextualBindings
     * @param array<string, string> $environmentBindings
     * @param array<string, true> $attributeTypes
     */
    private function __construct(
        private array $definitions,
        private array $definitionMeta,
        private array $classResources,
        private array $closureResources,
        private array $contextualBindings,
        private array $environmentBindings,
        private array $attributeTypes,
        private ?string $environment,
        private ?string $defaultMethod,
        private bool $methodAttributes,
        private bool $propertyAttributes,
    ) {}

    public static function from(Repository $repository): self
    {
        $definitions = $repository->getFunctionReference();
        $definitionMeta = [];
        foreach ($definitions as $id => $_definition) {
            $definitionMeta[$id] = $repository->getDefinitionMeta($id);
        }

        $contextualBindings = [];
        foreach ($repository->getContextualBindingShape() as $consumer => $dependencies) {
            foreach ($dependencies as $dependency) {
                $contextualBindings[$consumer][$dependency] = $repository->getContextualBinding(
                    $consumer,
                    $dependency,
                );
            }
        }

        return new self(
            definitions: $definitions,
            definitionMeta: $definitionMeta,
            classResources: $repository->getClassResource(),
            closureResources: $repository->getClosureResource(),
            contextualBindings: $contextualBindings,
            environmentBindings: self::snapshotEnvironmentBindings(
                $repository,
                $definitions,
                $contextualBindings,
            ),
            attributeTypes: array_fill_keys($repository->getRegisteredAttributeTypes(), true),
            environment: $repository->getEnvironment(),
            defaultMethod: $repository->getDefaultMethod(),
            methodAttributes: $repository->isMethodAttributeEnabled(),
            propertyAttributes: $repository->isPropertyAttributeEnabled(),
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function classResources(): array
    {
        return $this->classResources;
    }

    /** @return array<string, mixed> */
    public function classResourcesFor(string $class): array
    {
        return $this->classResources[$class] ?? [];
    }

    /** @return array<string, array{on: callable, params: array<int|string, mixed>}> */
    public function closureResources(): array
    {
        return $this->closureResources;
    }

    public function contextualBinding(string $consumer, string $dependency): mixed
    {
        return $this->contextualBindings[$consumer][$dependency] ?? null;
    }

    /** @return array<string, array<int, string>> */
    public function contextualBindingShape(): array
    {
        $shape = [];
        foreach ($this->contextualBindings as $consumer => $bindings) {
            $dependencies = array_keys($bindings);
            sort($dependencies, SORT_STRING);
            $shape[$consumer] = $dependencies;
        }
        ksort($shape, SORT_STRING);

        return $shape;
    }

    public function defaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    /** @return array<string, array{lifetime: LifetimeEnum, tags: array<int, string>}> */
    public function definitionMeta(): array
    {
        return $this->definitionMeta;
    }

    /** @return array{lifetime: LifetimeEnum, tags: array<int, string>} */
    public function definitionMetaFor(string $id): array
    {
        return $this->definitionMeta[$id] ?? ['lifetime' => LifetimeEnum::Singleton, 'tags' => []];
    }

    /** @return array<string, mixed> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    public function environmentConcrete(string $type): ?string
    {
        return $this->environmentBindings[$type] ?? null;
    }

    public function hasAttributeType(string $type): bool
    {
        return isset($this->attributeTypes[$type]);
    }

    public function hasContextualBinding(string $consumer, string $dependency): bool
    {
        return array_key_exists($dependency, $this->contextualBindings[$consumer] ?? []);
    }

    public function hasDefinition(string $id): bool
    {
        return isset($this->definitions[$id]) || array_key_exists($id, $this->definitions);
    }

    public function methodAttributesEnabled(): bool
    {
        return $this->methodAttributes;
    }

    public function propertyAttributesEnabled(): bool
    {
        return $this->propertyAttributes;
    }

    /** @return array<int, string> */
    public function registeredAttributeTypes(): array
    {
        return array_keys($this->attributeTypes);
    }

    /**
     * @param array<string, mixed> $definitions
     * @param array<string, array<string, mixed>> $contextualBindings
     * @return array<string, string>
     */
    private static function snapshotEnvironmentBindings(
        Repository $repository,
        array $definitions,
        array $contextualBindings,
    ): array {
        if ($repository->getEnvironment() === null) {
            return [];
        }

        $pending = [];
        foreach ($definitions as $definition) {
            if (is_string($definition) && class_exists($definition)) {
                $pending[] = $definition;
            }
        }
        foreach ($contextualBindings as $bindings) {
            foreach ($bindings as $binding) {
                if (is_string($binding) && (class_exists($binding) || interface_exists($binding))) {
                    $pending[] = $binding;
                }
            }
        }

        $bindings = [];
        $seen = [];
        while (($type = array_pop($pending)) !== null) {
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;

            $concrete = $repository->getEnvConcrete($type);
            if ($concrete !== null && class_exists($concrete)) {
                $bindings[$type] = $concrete;
                $pending[] = $concrete;
                $type = $concrete;
            }
            if (!class_exists($type)) {
                continue;
            }

            $constructor = ReflectionResource::getClassReflection($type)->getConstructor();
            if ($constructor === null) {
                continue;
            }
            foreach ($constructor->getParameters() as $parameter) {
                $parameterType = $parameter->getType();
                if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
                    continue;
                }

                $dependency = $parameterType->getName();
                $environmentConcrete = $repository->getEnvConcrete($dependency);
                if ($environmentConcrete !== null && class_exists($environmentConcrete)) {
                    $bindings[$dependency] = $environmentConcrete;
                    $pending[] = $environmentConcrete;
                } elseif (class_exists($dependency)) {
                    $pending[] = $dependency;
                }
            }
        }

        ksort($bindings, SORT_STRING);

        return $bindings;
    }
}
