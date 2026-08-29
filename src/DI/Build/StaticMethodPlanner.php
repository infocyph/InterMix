<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use ReflectionClass;
use ReflectionMethod;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @internal
 */
final class StaticMethodPlanner
{
    /**
     * @param ReflectionClass<object> $class
     * @return MethodPlan|null|string
     */
    public function plan(DefinitionGraph $graph, ReflectionClass $class): array|string|null
    {
        $resource = $graph->classResourcesFor($class->getName())['method'] ?? null;
        $supplied = $this->resourceParameters($resource);
        $methodName = $this->targetMethod($graph, $class, $resource);
        if ($methodName === null) {
            return null;
        }

        $method = $class->getMethod($methodName);
        if (!$method->isPublic() || $method->isStatic()) {
            return "method '{$class->getName()}::{$methodName}()' requires reflection-based invocation";
        }
        if ($this->hasDynamicAttributes($graph, $method)) {
            return "method '{$class->getName()}::{$methodName}()' has runtime attributes";
        }

        $parameters = new StaticParameterPlanner()->callablePlan(
            $graph,
            $method->getDeclaringClass(),
            $method,
            $supplied,
            'method',
            false,
        );
        if (is_string($parameters)) {
            return $parameters;
        }

        return [
            'method' => $methodName,
            'arguments' => $parameters['arguments'],
            'dependencies' => $parameters['dependencies'],
        ];
    }

    private function hasDynamicAttributes(DefinitionGraph $graph, ReflectionMethod $method): bool
    {
        if (!$graph->methodAttributesEnabled()) {
            return false;
        }
        if ($method->getAttributes() !== []) {
            return true;
        }

        return array_any(
            $method->getParameters(),
            static fn(\ReflectionParameter $parameter): bool => $parameter->getAttributes() !== [],
        );
    }

    /** @return array<int|string, mixed> */
    private function resourceParameters(mixed $resource): array
    {
        if (!is_array($resource)) {
            return [];
        }

        $parameters = $resource['params'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }

    /** @param ReflectionClass<object> $class */
    private function targetMethod(DefinitionGraph $graph, ReflectionClass $class, mixed $resource): ?string
    {
        $registered = is_array($resource) ? ($resource['on'] ?? null) : null;
        if (is_string($registered) && $registered !== '') {
            return $class->hasMethod($registered) ? $registered : null;
        }

        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        if ($callOn) {
            return is_string($callOn) && $class->hasMethod($callOn) ? $callOn : null;
        }

        $default = $graph->defaultMethod();
        if (is_string($default) && $default !== '') {
            return $class->hasMethod($default) ? $default : null;
        }

        return $class->hasMethod('__invoke') ? '__invoke' : null;
    }
}
