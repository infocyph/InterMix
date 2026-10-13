<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\ParameterResolver;
use Infocyph\InterMix\DI\Resolver\PropertyResolver;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Internal\ReflectionResource;

/** @internal */
final readonly class RuntimeIslandResolver
{
    private ParameterResolver $parameterResolver;

    private PropertyResolver $propertyResolver;

    public function __construct(private Repository $repository)
    {
        [$classResolver, $this->parameterResolver] = new InjectedCall($repository)->reflectionResolvers();
        $this->propertyResolver = new PropertyResolver($repository);
        $this->propertyResolver->setClassResolverInstance($classResolver);
    }

    public function applyAttributedProperty(object $instance, string $declaringClass, string $propertyName): void
    {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $registered = $this->repository->getClassResourceFor($declaringClass)['property'] ?? [];
        $this->propertyResolver->resolveProperty(
            $property,
            $instance,
            is_array($registered) ? array_filter($registered, is_string(...), ARRAY_FILTER_USE_KEY) : [],
            $this->repository->isPropertyAttributeEnabled(),
        );
    }

    public function assignProperty(
        object $instance,
        string $declaringClass,
        string $propertyName,
        mixed $value,
    ): void {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $property->setValue($property->isStatic() ? null : $instance, $value);
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
}
