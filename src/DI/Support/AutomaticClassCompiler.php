<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Builds direct class recipes only when dynamic DI behavior cannot apply.
 *
 * @internal
 */
final class AutomaticClassCompiler
{
    /**
     * @param Container $container Fully registered build-time container.
     * @param string $definition Registered class-string definition.
     * @return array{code: string|null, signature: array<string, mixed>|null, reason: string}
     */
    public function compile(Container $container, string $definition): array
    {
        $class = ReflectionResource::getClassReflection($definition);
        if (!$class->isInstantiable()) {
            return $this->skipped('class definition is not instantiable');
        }

        $issue = $this->eligibilityIssue($container, $class);
        if ($issue !== null) {
            return $this->skipped($issue);
        }

        $constructor = $class->getConstructor();
        $arguments = $constructor === null
            ? []
            : $this->compileConstructorArguments($class, $constructor->getParameters());
        $fqcn = '\\' . ltrim($class->getName(), '\\');

        return [
            'code' => "new {$fqcn}(" . implode(', ', $arguments) . ')',
            'signature' => ['kind' => 'class', 'class' => $class->getName()],
            'reason' => '',
        ];
    }

    /**
     * @param ReflectionClass<object> $class Constructor owner.
     * @param array<int, ReflectionParameter> $parameters Constructor parameters.
     * @return array<int, string>
     */
    private function compileConstructorArguments(ReflectionClass $class, array $parameters): array
    {
        $arguments = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependency = $this->normalizedDependencyType($class, $type->getName());
                $arguments[] = '$c->get(\\' . ltrim((string) $dependency, '\\') . '::class)';

                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = var_export($parameter->getDefaultValue(), true);

                continue;
            }
            if ($parameter->allowsNull()) {
                $arguments[] = 'null';
            }
        }

        return $arguments;
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param ReflectionClass<object> $class Constructor owner.
     * @param ReflectionParameter $parameter Constructor parameter being inspected.
     * @param array<string, true> $resolvedTypes Previously inspected dependency types.
     */
    private function constructorParameterIssue(
        Container $container,
        ReflectionClass $class,
        ReflectionParameter $parameter,
        array &$resolvedTypes,
    ): ?string {
        if ($parameter->isVariadic()) {
            return "constructor parameter '{$parameter->getName()}' is variadic";
        }
        if ($parameter->getAttributes() !== []) {
            return "constructor parameter '{$parameter->getName()}' has attributes";
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return "constructor parameter '{$parameter->getName()}' has a union or intersection type";
        }
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable() && $this->isExportable($parameter->getDefaultValue())) {
                return null;
            }

            return $parameter->allowsNull()
                ? null
                : "constructor parameter '{$parameter->getName()}' cannot be represented safely";
        }

        $dependency = $this->normalizedDependencyType($class, $type->getName());
        if ($dependency === null) {
            return "constructor parameter '{$parameter->getName()}' has an unsupported relative type";
        }

        $repository = $container->getRepository();
        if ($repository->hasFunctionReference($parameter->getName())) {
            return "constructor parameter '{$parameter->getName()}' is shadowed by a named definition";
        }
        if ($repository->hasContextualBinding($class->getName(), $dependency)) {
            return "constructor dependency '$dependency' has a contextual binding";
        }
        if (isset($resolvedTypes[$dependency])) {
            return "constructor dependency '$dependency' occurs more than once";
        }
        $resolvedTypes[$dependency] = true;

        return null;
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param ReflectionClass<object> $class Reflected automatic class definition.
     */
    private function eligibilityIssue(Container $container, ReflectionClass $class): ?string
    {
        $repository = $container->getRepository();
        $className = $class->getName();
        $resources = array_keys($repository->getClassResourceFor($className));
        if ($resources !== []) {
            sort($resources, SORT_STRING);

            return 'class has registered ' . implode(', ', $resources) . ' resources';
        }

        $method = $this->implicitMethod($container, $class);
        if ($method !== null) {
            return "class invokes implicit method '$method'";
        }
        if ($this->hasInjectablePropertyAttribute($container, $class)) {
            return 'class has an enabled injectable property attribute';
        }

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return null;
        }

        $resolvedTypes = [];
        foreach ($constructor->getParameters() as $parameter) {
            $issue = $this->constructorParameterIssue($container, $class, $parameter, $resolvedTypes);
            if ($issue !== null) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param ReflectionClass<object> $class Class being inspected.
     */
    private function hasInjectablePropertyAttribute(Container $container, ReflectionClass $class): bool
    {
        if (!$container->getRepository()->isPropertyAttributeEnabled()) {
            return false;
        }

        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $current->getName()
                    && $this->propertyHasRegisteredAttribute($container, $property)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param ReflectionClass<object> $class Class being inspected.
     */
    private function implicitMethod(Container $container, ReflectionClass $class): ?string
    {
        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        if (is_string($callOn) && $callOn !== '' && $class->hasMethod($callOn)) {
            return $callOn;
        }

        $default = $container->getRepository()->getDefaultMethod();
        if (is_string($default) && $default !== '' && $class->hasMethod($default)) {
            return $default;
        }

        return $class->hasMethod('__invoke') ? '__invoke' : null;
    }

    private function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn($item) => $this->isExportable($item));
    }

    /**
     * @param ReflectionClass<object> $class Declaring class used for relative types.
     * @param string $type Reflected dependency type.
     */
    private function normalizedDependencyType(ReflectionClass $class, string $type): ?string
    {
        if ($type === 'self') {
            return $class->getName();
        }
        if ($type === 'parent') {
            $parent = $class->getParentClass();

            return $parent instanceof ReflectionClass ? $parent->getName() : null;
        }

        return $type === 'static' ? null : $type;
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param ReflectionProperty $property Property being inspected.
     */
    private function propertyHasRegisteredAttribute(Container $container, ReflectionProperty $property): bool
    {
        return array_any($property->getAttributes(), fn($attribute) => $attribute->getName() === Inject::class
            || $container->attributeRegistry()->has($attribute->getName()));
    }

    /**
     * @param string $reason Human-readable cache report reason.
     * @return array{code: null, signature: null, reason: string}
     */
    private function skipped(string $reason): array
    {
        return ['code' => null, 'signature' => null, 'reason' => $reason];
    }
}
