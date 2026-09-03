<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string<object>, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
 * @phpstan-type AliasPlan array{kind: 'alias', target: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string<object>, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, postMethod: MethodPlan|null, dependencies: list<string>}
 * @phpstan-type FactoryPlan array{kind: 'factory', class: class-string<object>, method: string|null, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type InvocationPlan array{kind: 'invocation', class: class-string<object>, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, invocation: MethodPlan, dependencies: list<string>}
 * @phpstan-type ValuePlan array{kind: 'value', code: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type ServicePlan AliasPlan|ClassPlan|FactoryPlan|InvocationPlan|ValuePlan
 * @internal
 */
final class StaticRuntimePlanner
{
    /** @return array{plans: array<string, ServicePlan>, skipped: array<string, string>} */
    public function plan(DefinitionGraph $graph): array
    {
        [$plans, $skipped] = $this->buildPlans($graph);
        $plans = $this->expandImplicitClasses($graph, $plans, $skipped);
        $plans = $this->pruneUnavailableDependencies($plans, $skipped);
        $plans = $this->pruneCycles($plans, $skipped);
        ksort($plans, SORT_STRING);

        return ['plans' => $plans, 'skipped' => $skipped];
    }

    /** @return array{array<string, ServicePlan>, array<string, string>} */
    private function buildPlans(DefinitionGraph $graph): array
    {
        $plans = [];
        $skipped = [];
        $definitions = $graph->definitions();
        $aliasCycles = $this->detectAliasCycles($definitions);
        ksort($definitions, SORT_STRING);

        foreach ($definitions as $id => $definition) {
            if (isset($aliasCycles[$id])) {
                $skipped[$id] = 'alias graph contains a cycle';

                continue;
            }

            $plan = $this->planDefinition($graph, $id, $definition);
            if (is_string($plan)) {
                $skipped[$id] = $plan;

                continue;
            }
            $plans[$id] = $plan;
        }

        return [$plans, $skipped];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return ClassPlan|string
     */
    private function classPlan(
        DefinitionGraph $graph,
        string $id,
        ReflectionClass $class,
    ): array|string {
        if (!$class->isInstantiable()) {
            return 'class definition is not instantiable';
        }
        if (!$graph->injectionEnabled()) {
            return $this->genericClassPlan($graph, $id, $class);
        }

        $dynamicReason = new StaticFeatureClassifier()->dynamicReason($graph, $class);
        if ($dynamicReason !== null) {
            return $dynamicReason;
        }

        $constructor = new StaticParameterPlanner()->constructorPlan($graph, $class);
        if (is_string($constructor)) {
            return $constructor;
        }
        $property = new StaticPropertyPlanner()->plan($graph, $class);
        $postMethod = new StaticMethodPlanner()->plan($graph, $class);
        $methodDependencies = is_array($postMethod) ? $postMethod['dependencies'] : [];

        return [
            'kind' => 'class',
            'class' => $class->getName(),
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => $constructor['arguments'],
            'properties' => $property['properties'],
            'postMethod' => $postMethod,
            'dependencies' => array_values(array_unique([
                ...$constructor['dependencies'],
                ...$property['dependencies'],
                ...$methodDependencies,
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $definitions
     * @return array<string, true>
     */
    private function detectAliasCycles(array $definitions): array
    {
        $cyclic = [];
        foreach ($definitions as $id => $definition) {
            if (!$definition instanceof AliasDefinition) {
                continue;
            }

            $path = [];
            $positions = [];
            $current = $id;
            while (true) {
                $alias = $definitions[$current] ?? null;
                if (!$alias instanceof AliasDefinition) {
                    break;
                }
                if (isset($positions[$current])) {
                    foreach (array_slice($path, $positions[$current]) as $cycleId) {
                        $cyclic[$cycleId] = true;
                    }

                    break;
                }

                $positions[$current] = count($path);
                $path[] = $current;
                $current = $alias->target;
            }
        }

        return $cyclic;
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, string> $skipped
     * @return array<string, ServicePlan>
     */
    private function expandImplicitClasses(
        DefinitionGraph $graph,
        array $plans,
        array &$skipped,
    ): array {
        do {
            $changed = false;
            foreach ($plans as $plan) {
                foreach ($plan['dependencies'] as $dependency) {
                    if ($this->isKnownDependency($graph, $plans, $skipped, $dependency)) {
                        continue;
                    }
                    $implicit = $this->implicitDependencyPlan($graph, $dependency);
                    if (is_string($implicit)) {
                        $skipped[$dependency] = $implicit;

                        continue;
                    }
                    $plans[$dependency] = $implicit;
                    $changed = true;
                }
            }
        } while ($changed);

        return $plans;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return ClassPlan|string
     */
    private function genericClassPlan(
        DefinitionGraph $graph,
        string $id,
        ReflectionClass $class,
    ): array|string {
        if ($graph->classResourcesFor($class->getName()) !== []) {
            return 'injection-off class has registered generic resources';
        }
        if ($graph->defaultMethod() !== null) {
            return 'injection-off class has a configured default method';
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return 'injection-off class requires constructor parameters';
        }

        return [
            'kind' => 'class',
            'class' => $class->getName(),
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => [],
            'properties' => [],
            'postMethod' => null,
            'dependencies' => [],
        ];
    }

    /**
     * @param array<string, array{dependencies: list<string>}> $plans
     * @param array<string, true> $remaining
     */
    private function hasRemainingDependency(array $plans, array $remaining, string $id): bool
    {
        foreach ($plans[$id]['dependencies'] as $dependency) {
            if (isset($remaining[$dependency])) {
                return true;
            }
        }

        return false;
    }

    /** @return ClassPlan|string */
    private function implicitDependencyPlan(DefinitionGraph $graph, string $dependency): array|string
    {
        if (!class_exists($dependency)) {
            return 'implicit dependency is not an autowireable class';
        }

        return $this->classPlan(
            $graph,
            $dependency,
            ReflectionResource::getClassReflection($dependency),
        );
    }

    /** @param array<int|string, mixed> $definition */
    private function isCallableArrayDefinition(array $definition): bool
    {
        return isset($definition[0])
            && is_string($definition[0])
            && class_exists($definition[0]);
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

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, string> $skipped
     */
    private function isKnownDependency(
        DefinitionGraph $graph,
        array $plans,
        array $skipped,
        string $dependency,
    ): bool {
        return isset($plans[$dependency])
            || isset($skipped[$dependency])
            || $graph->hasDefinition($dependency);
    }

    /** @return AliasPlan */
    private function planAlias(DefinitionGraph $graph, string $id, AliasDefinition $definition): array
    {
        $target = $definition->target;
        $definitions = $graph->definitions();
        $alias = $definitions[$target] ?? null;

        while ($alias instanceof AliasDefinition) {
            if ($graph->definitionMetaFor($target)['lifetime'] !== LifetimeEnum::Transient) {
                break;
            }
            $target = $alias->target;
            $alias = $definitions[$target] ?? null;
        }

        return [
            'kind' => 'alias',
            'target' => $target,
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => [],
            'properties' => [],
            'dependencies' => [$target],
        ];
    }

    /**
     * @param array<int|string, mixed> $definition
     * @return ClassPlan|InvocationPlan|string
     */
    private function planArrayDefinition(DefinitionGraph $graph, string $id, array $definition): array|string
    {
        $className = $definition[0] ?? null;
        if (!is_string($className) || !class_exists($className)) {
            return 'array definition requires the dynamic runtime';
        }

        $methodName = $definition[1] ?? null;
        if (!is_string($methodName)) {
            return $this->classPlan(
                $graph,
                $id,
                ReflectionResource::getClassReflection($className),
            );
        }

        return new StaticInvocationPlanner()->plan($graph, $id, $className, $methodName);
    }

    /** @return ServicePlan|string */
    private function planDefinition(DefinitionGraph $graph, string $id, mixed $definition): array|string
    {
        if ($graph->requiresDynamicService($id)) {
            return 'service requires the dynamic runtime';
        }

        if ($definition instanceof AliasDefinition) {
            return $this->planAlias($graph, $id, $definition);
        }
        if ($definition instanceof FactoryDefinition) {
            return new StaticFactoryPlanner()->plan($graph, $id, $definition);
        }
        if (is_array($definition) && $this->isCallableArrayDefinition($definition)) {
            return $this->planArrayDefinition($graph, $id, $definition);
        }

        $valuePlan = $this->valuePlan($graph, $id, $definition);
        if ($valuePlan !== null) {
            return $valuePlan;
        }
        if (!is_string($definition) || !class_exists($definition)) {
            return 'definition requires the dynamic runtime';
        }

        return $this->classPlan(
            $graph,
            $id,
            ReflectionResource::getClassReflection($definition),
        );
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, string> $skipped
     * @return array<string, ServicePlan>
     */
    private function pruneCycles(array $plans, array &$skipped): array
    {
        $remaining = array_fill_keys(array_keys($plans), true);
        do {
            $changed = false;
            foreach (array_keys($remaining) as $id) {
                if ($this->hasRemainingDependency($plans, $remaining, $id)) {
                    continue;
                }
                unset($remaining[$id]);
                $changed = true;
            }
        } while ($changed);

        foreach (array_keys($remaining) as $id) {
            $skipped[$id] = 'static dependency graph contains or depends on a cycle';
            unset($plans[$id]);
        }

        return $plans;
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, string> $skipped
     * @return array<string, ServicePlan>
     */
    private function pruneUnavailableDependencies(array $plans, array &$skipped): array
    {
        do {
            $changed = false;
            foreach ($plans as $id => $plan) {
                foreach ($plan['dependencies'] as $dependency) {
                    if (isset($plans[$dependency])) {
                        continue;
                    }
                    $skipped[$id] = "dependency '$dependency' is not statically compiled";
                    unset($plans[$id]);
                    $changed = true;

                    break;
                }
            }
        } while ($changed);

        return $plans;
    }

    /** @return ValuePlan|null */
    private function valuePlan(DefinitionGraph $graph, string $id, mixed $definition): ?array
    {
        if ($id === ContainerInterface::class && $definition instanceof Container) {
            return [
                'kind' => 'value',
                'code' => '$this',
                'lifetime' => LifetimeEnum::Singleton,
                'arguments' => [],
                'properties' => [],
                'dependencies' => [],
            ];
        }
        if (!$this->isExportable($definition) || (is_array($definition) && $this->isCallableArrayDefinition($definition))) {
            return null;
        }
        if (is_string($definition) && class_exists($definition)) {
            return null;
        }

        return [
            'kind' => 'value',
            'code' => var_export($definition, true),
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => [],
            'properties' => [],
            'dependencies' => [],
        ];
    }
}
