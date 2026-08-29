<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string<object>, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'|'registered'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string<object>, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, postMethod: MethodPlan|null, dependencies: list<string>}
 * @internal
 */
final class StaticReturnRenderer
{
    /**
     * Registered class definitions resolve to their service instance in the
     * development resolver even when an implicit post-construction method runs.
     * Generated definitions preserve that behavior and deliberately discard the
     * post-method return value here.
     *
     * @param ClassPlan $plan
     * @param MethodPlan $method
     * @param array<string, int> $slots
     */
    public function invocationStatement(array $plan, array $method, array $slots, int $slot): string
    {
        unset($slot);

        $expression = new StaticRuntimeIslandRenderer()->methodExpression(
            $plan['class'],
            $method,
            $slots,
        );

        return "\n        {$expression};\n";
    }

    /**
     * @param array<string, mixed> $plans
     * @param array<string, int> $slots
     */
    public function renderDispatch(array $plans, array $slots): string
    {
        unset($plans, $slots);

        return '';
    }

    /** @param array<string, mixed> $plans */
    public function renderProperties(array $plans): string
    {
        unset($plans);

        return '';
    }
}
