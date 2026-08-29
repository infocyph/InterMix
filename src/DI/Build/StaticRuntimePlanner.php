<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionClass;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type ValuePlan array{kind: 'value', code: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type ServicePlan ClassPlan|ValuePlan
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
        ksort($definitions, SORT_STRING);

        foreach ($definitions as $id => $definition) {
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

        $dynamicReason = (new StaticFeatureClassifier())->dynamicReason($graph, $class);
        if ($dynamicReason !== null) {
            return $dynamicReason;
        }

        $method = $this->implicitMethod($graph, $class);
        if ($method !== null) {
            return "class invokes implicit method '$method'";
        }

        $constructor = (new StaticParameterPlanner())->constructorPlan($graph, $class);
        if (is_string($constructor)) {
            return $constructor;
        }

        return [
            'kind' => 'class',
            'class' => $class->getName(),
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => $constructor['arguments'],
            'dependencies' => $constructor['dependencies'],
        ];
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

    /** @param ReflectionClass<object> $class */
    private function implicitMethod(DefinitionGraph $graph, ReflectionClass $class): ?string
    {
        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        if (is_string($callOn) && $callOn !== '' && $class->hasMethod($callOn)) {
            return $callOn;
        }

        $default = $graph->defaultMethod();
        if (is_string($default) && $default !== '' && $class->hasMethod($default)) {
            return $default;
        }

        return $class->hasMethod('__invoke') ? '__invoke' : null;
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

    /** @return ServicePlan|string */
    private function planDefinition(DefinitionGraph $graph, string $id, mixed $definition): array|string
    {
        if ($graph->requiresDynamicService($id)) {
            return 'service has lifecycle hooks and requires the dynamic runtime';
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
        if (!$this->isExportable($definition) || $this->isCallableArrayDefinition($definition)) {
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
            'dependencies' => [],
        ];
    }

    private function isCallableArrayDefinition(mixed $definition): bool
    {
        return is_array($definition)
            && isset($definition[0])
            && is_string($definition[0])
            && class_exists($definition[0]);
    }
}
