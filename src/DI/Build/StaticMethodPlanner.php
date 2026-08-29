<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @internal
 */
final class StaticMethodPlanner
{
    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $supplied
     * @return MethodPlan|string
     */
    public function explicitPlan(
        DefinitionGraph $graph,
        ReflectionClass $class,
        string $methodName,
        array $supplied = [],
    ): array|string {
        if (!$class->hasMethod($methodName)) {
            return "method '{$class->getName()}::{$methodName}()' does not exist";
        }

        $resource = $graph->classResourcesFor($class->getName())['method'] ?? null;

        return $this->planMethod(
            $graph,
            $class->getMethod($methodName),
            $supplied + $this->resourceParameters($resource),
        );
    }

    /**
     * @param ReflectionClass<object> $class
     * @return MethodPlan|null|string
     */
    public function plan(DefinitionGraph $graph, ReflectionClass $class): array|string|null
    {
        $resource = $graph->classResourcesFor($class->getName())['method'] ?? null;
        $methodName = $this->targetMethod($graph, $class, $resource);
        if ($methodName === null) {
            return null;
        }

        return $this->planMethod(
            $graph,
            $class->getMethod($methodName),
            $this->resourceParameters($resource),
        );
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{configured: bool, method: string|null}
     */
    private function configuredMethod(ReflectionClass $class, mixed $candidate): array
    {
        if (!$candidate) {
            return ['configured' => false, 'method' => null];
        }

        return [
            'configured' => true,
            'method' => is_string($candidate) && $class->hasMethod($candidate) ? $candidate : null,
        ];
    }

    private function hasDynamicParameterAttribute(DefinitionGraph $graph, ReflectionParameter $parameter): bool
    {
        return array_any(
            $parameter->getAttributes(),
            fn(ReflectionAttribute $attribute): bool => $attribute->getName() !== Inject::class
                && $graph->hasAttributeType($attribute->getName()),
        );
    }

    /**
     * @param array<int|string, mixed> $supplied
     * @return MethodPlan|string
     */
    private function planMethod(DefinitionGraph $graph, ReflectionMethod $method, array $supplied): array|string
    {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        if (!$method->isPublic() || $method->isStatic()) {
            return "method '{$className}::{$methodName}()' requires reflection-based invocation";
        }

        if ($graph->methodAttributesEnabled()
            && array_any(
                $method->getParameters(),
                fn(ReflectionParameter $parameter): bool => $this->hasDynamicParameterAttribute($graph, $parameter),
            )
        ) {
            return "method '{$className}::{$methodName}()' has runtime attributes";
        }

        $attributeArguments = [];
        if ($graph->methodAttributesEnabled()) {
            $attributeArguments = new StaticInjectPlanner()->methodArguments($graph, $method);
            if (is_string($attributeArguments)) {
                return $attributeArguments;
            }
        }

        $parameters = new StaticParameterPlanner()->callablePlan(
            $graph,
            $method->getDeclaringClass(),
            $method,
            $supplied,
            'method',
            false,
            $attributeArguments,
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
        $registered = $this->configuredMethod(
            $class,
            is_array($resource) ? ($resource['on'] ?? null) : null,
        );
        if ($registered['configured']) {
            return $registered['method'];
        }

        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $this->configuredMethod(
            $class,
            $class->hasConstant($constant) ? $class->getConstant($constant) : null,
        );
        if ($callOn['configured']) {
            return $callOn['method'];
        }

        $default = $this->configuredMethod($class, $graph->defaultMethod());
        if ($default['configured']) {
            return $default['method'];
        }

        return $class->hasMethod('__invoke') ? '__invoke' : null;
    }
}
