<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\ClassResolver;
use Infocyph\InterMix\DI\Resolver\ParameterResolver;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionNamedType;
use ReflectionProperty;

/** @internal */
final readonly class RuntimeIslandResolver
{
    private ClassResolver $classResolver;

    private ParameterResolver $parameterResolver;

    public function __construct(private Repository $repository)
    {
        [$this->classResolver, $this->parameterResolver] = new InjectedCall($repository)->reflectionResolvers();
    }

    public function applyAttributedProperty(object $instance, string $declaringClass, string $propertyName): void
    {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $registered = $this->repository->getClassResourceFor($declaringClass)['property'] ?? null;
        if (is_array($registered) && array_key_exists($propertyName, $registered)) {
            $this->setPropertyValue($property, $instance, $registered[$propertyName]);

            return;
        }

        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push(
                "prop {$propertyName} of {$declaringClass}",
                TraceLevelEnum::Verbose,
            );
        }

        $value = $this->resolveAttributedPropertyValue($property);
        if ($value !== AttributeResolution::Unresolved) {
            $this->setPropertyValue($property, $instance, $value);
        }
    }

    public function assignProperty(
        object $instance,
        string $declaringClass,
        string $propertyName,
        mixed $value,
    ): void {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $this->setPropertyValue($property, $instance, $value);
    }

    public function invokeMethod(object $instance, string $className, string $methodName): mixed
    {
        $method = ReflectionResource::getClassReflection($className)->getMethod($methodName);
        $resource = $this->repository->getClassResourceFor($className)['method'] ?? null;
        $parameters = is_array($resource) && is_array($resource['params'] ?? null)
            ? $resource['params']
            : [];

        return $method->invokeArgs(
            $method->isStatic() ? null : $instance,
            $this->parameterResolver->resolve($method, $parameters, 'method'),
        );
    }

    private function resolveAttributedPropertyValue(ReflectionProperty $property): mixed
    {
        $inject = $property->getAttributes(Inject::class)[0] ?? null;
        if ($inject !== null) {
            if ($inject->getArguments() !== []) {
                return $this->classResolver->resolveInject($inject->newInstance());
            }

            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new ContainerException(
                    'Malformed #[Inject] or invalid property type on '
                    . "{$property->getDeclaringClass()->getName()}::\${$property->getName()}",
                );
            }

            $resolved = $this->classResolver->resolveInject(new Inject($type->getName()));
            if ($resolved === AttributeResolution::Unresolved) {
                throw new ContainerException(
                    "Failed to resolve {$type->getName()} for property injection.",
                );
            }

            return $resolved;
        }

        $registry = $this->repository->attributeRegistry();
        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if (!$registry->has($instance::class)) {
                continue;
            }

            $value = $registry->resolve($instance, $property);
            if ($value !== AttributeResolution::Unresolved) {
                return $value;
            }
        }

        return AttributeResolution::Unresolved;
    }

    private function setPropertyValue(ReflectionProperty $property, object $instance, mixed $value): void
    {
        $property->setValue($property->isStatic() ? null : $instance, $value);
    }
}
