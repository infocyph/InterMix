<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string, bind?: class-string, nullable?: bool}|array{kind: 'value', code: string, bind?: class-string, nullable?: bool}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, parameterNames?: list<string>, static?: bool, bound?: bool, runtime?: bool}
 * @phpstan-type ServicePlan array{kind: string, properties: list<PropertyPlan>, postMethod?: MethodPlan|null, invocation?: MethodPlan}
 * @internal
 */
final class StaticRuntimeRequirements
{
    /**
     * Collect unsupported definitions, runtime injection islands and lifecycle behavior requiring
     * protected fallback.
     *
     * @param array<string, ServicePlan> $plans
     * @param array<string, string> $skipped
     * @return array<string, string>
     */
    public function fallbackReasons(DefinitionGraph $graph, array $plans, array $skipped): array
    {
        $reasons = $skipped;

        foreach ($plans as $rawId => $plan) {
            $id = (string) $rawId;
            $property = array_find($plan['properties'], static fn(array $property): bool => ($property['runtime'] ?? null) === 'attribute');
            if ($property !== null) {
                $reasons[$id] = sprintf(
                    "property '%s::\$%s' requires runtime attribute handling",
                    $property['declaring'],
                    $property['property'],
                );
            }

            $method = $plan['postMethod'] ?? $plan['invocation'] ?? null;
            if (is_array($method) && ($method['runtime'] ?? false)) {
                $reasons[$id] = sprintf(
                    "method '%s' requires runtime parameter/attribute resolution",
                    $method['method'],
                );
            }

            if ($graph->hasResolvingHook($id) || $graph->hasResolvedHook($id)) {
                $reasons[$id] = 'resolution lifecycle hooks require the dynamic hook graph';
            }
        }

        foreach ($graph->scopeLeaveHookScopes() as $scope) {
            $reasons['scope:' . $scope] = 'scope-leave hooks require the dynamic hook graph';
        }

        ksort($reasons, SORT_STRING);

        return $reasons;
    }
}
