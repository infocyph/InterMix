<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\AutowireDefinition;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\InputDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\RuntimeFactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\InterMix\DI\Support\ValueDefinition;
use Psr\Container\ContainerInterface;

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
     * @param array<class-string, class-string> $attributeResolvers
     * @param array<string, true> $dynamicServiceIds
     * @param array<string, true> $resolvingHookIds
     * @param array<string, true> $resolvedHookIds
     * @param array<string, true> $scopeLeaveHookScopes
     */
    private function __construct(
        private array $definitions,
        private array $definitionMeta,
        private array $classResources,
        private array $closureResources,
        private array $contextualBindings,
        private array $environmentBindings,
        private array $attributeResolvers,
        private array $dynamicServiceIds,
        private array $resolvingHookIds,
        private array $resolvedHookIds,
        private array $scopeLeaveHookScopes,
        private ?string $environment,
        private ?string $defaultMethod,
        private bool $injectionEnabled,
        private bool $methodAttributes,
        private bool $propertyAttributes,
    ) {}

    /**
     * @param array<int, string> $dynamicServiceIds
     * @param array<int, string> $resolvingHookIds
     * @param array<int, string> $resolvedHookIds
     * @param array<int, string> $scopeLeaveHookScopes
     */
    public static function from(
        Repository $repository,
        array $dynamicServiceIds = [],
        array $resolvingHookIds = [],
        array $resolvedHookIds = [],
        array $scopeLeaveHookScopes = [],
    ): self {
        $definitions = $repository->getFunctionReference();
        $definitionMeta = [];
        foreach ($definitions as $rawId => $_definition) {
            $id = (string) $rawId;
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
            environmentBindings: new EnvironmentBindingSnapshot()->capture(
                $repository,
                $definitions,
                $contextualBindings,
            ),
            attributeResolvers: $repository->getRegisteredAttributeResolvers(),
            dynamicServiceIds: array_fill_keys($dynamicServiceIds, true),
            resolvingHookIds: array_fill_keys([
                ...$resolvingHookIds,
                ...$repository->getResolvingHookIds(),
            ], true),
            resolvedHookIds: array_fill_keys([
                ...$resolvedHookIds,
                ...$repository->getResolvedHookIds(),
            ], true),
            scopeLeaveHookScopes: array_fill_keys([
                ...$scopeLeaveHookScopes,
                ...$repository->getScopeLeaveHookScopes(),
            ], true),
            environment: $repository->getEnvironment(),
            defaultMethod: $repository->getDefaultMethod(),
            injectionEnabled: !ContainerAccess::resolver($repository->container()) instanceof GenericCall,
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

    /** @return array<string, array<string, mixed>> */
    public function contextualBindings(): array
    {
        return $this->contextualBindings;
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

    /** @return array<int, string> */
    public function dynamicServiceIds(): array
    {
        return array_keys($this->dynamicServiceIds);
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    /** @return array<string, string> */
    public function environmentBindings(): array
    {
        return $this->environmentBindings;
    }

    public function environmentConcrete(string $type): ?string
    {
        return $this->environmentBindings[$type] ?? null;
    }

    public function hasAttributeType(string $type): bool
    {
        return isset($this->attributeResolvers[$type]);
    }

    public function hasContextualBinding(string $consumer, string $dependency): bool
    {
        return array_key_exists($dependency, $this->contextualBindings[$consumer] ?? []);
    }

    public function hasDefinition(string $id): bool
    {
        return isset($this->definitions[$id]) || array_key_exists($id, $this->definitions);
    }

    public function hasResolvedHook(string $id): bool
    {
        return isset($this->resolvedHookIds[$id]);
    }

    public function hasResolvingHook(string $id): bool
    {
        return isset($this->resolvingHookIds[$id]);
    }

    public function hasScopeLeaveHook(string $scope): bool
    {
        return isset($this->scopeLeaveHookScopes[$scope]);
    }

    public function injectionEnabled(): bool
    {
        return $this->injectionEnabled;
    }

    public function methodAttributesEnabled(): bool
    {
        return $this->methodAttributes;
    }

    public function propertyAttributesEnabled(): bool
    {
        return $this->propertyAttributes;
    }

    /** @return array<class-string, class-string> */
    public function registeredAttributeResolvers(): array
    {
        return $this->attributeResolvers;
    }

    /** @return array<int, string> */
    public function registeredAttributeTypes(): array
    {
        return array_keys($this->attributeResolvers);
    }

    /** @return array<int, string> */
    public function resolvedHookIds(): array
    {
        return array_keys($this->resolvedHookIds);
    }

    public function requiresDynamicService(string $id): bool
    {
        return isset($this->dynamicServiceIds[$id]);
    }

    public function requiresReleaseIdentity(): bool
    {
        if ($this->closureResources !== []
            || $this->resolvingHookIds !== []
            || $this->resolvedHookIds !== []
            || $this->scopeLeaveHookScopes !== []
        ) {
            return true;
        }

        foreach ($this->definitions as $rawId => $definition) {
            if ($this->definitionIsOpaque((string) $rawId, $definition)) {
                return true;
            }
        }
        foreach ($this->contextualBindings as $bindings) {
            foreach ($bindings as $binding) {
                if (!$this->isPortableMetadata($binding)) {
                    return true;
                }
            }
        }
        foreach ($this->classResources as $resources) {
            if (!$this->isPortableMetadata($resources)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function resolvingHookIds(): array
    {
        return array_keys($this->resolvingHookIds);
    }

    /** @return array<int, string> */
    public function scopeLeaveHookScopes(): array
    {
        return array_keys($this->scopeLeaveHookScopes);
    }

    private function definitionIsOpaque(string $id, mixed $definition): bool
    {
        if (($id === ContainerInterface::class || $id === RuntimeContainerInterface::class)
            && $definition instanceof Container
        ) {
            return false;
        }
        if ($definition instanceof RuntimeFactoryDefinition) {
            return true;
        }
        if ($definition instanceof ValueDefinition) {
            return !$this->isPortableMetadata($definition->value);
        }
        if ($definition instanceof AutowireDefinition) {
            return !$this->isPortableMetadata($definition->arguments)
                || !$this->isPortableMetadata($definition->properties);
        }
        if ($definition instanceof AliasDefinition
            || $definition instanceof FactoryDefinition
            || $definition instanceof InputDefinition
        ) {
            return false;
        }

        return !$this->isPortableMetadata($definition);
    }

    private function isPortableMetadata(mixed $value): bool
    {
        if ($value instanceof FactoryDefinition || $value instanceof ServiceReference) {
            return true;
        }
        if (is_scalar($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return BoundedValueInspector::isScalarNullArray($value);
    }
}
