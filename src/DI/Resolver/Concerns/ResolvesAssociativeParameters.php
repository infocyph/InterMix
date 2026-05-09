<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionParameter;

trait ResolvesAssociativeParameters
{
    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed>|mixed $supplied
     */
    private function appendConstructorSupplied(
        ReflectionClass $class,
        string $type,
        mixed $supplied,
    ): void {
        if ($type !== 'constructor' || $supplied === null || $class->getConstructor() === null) {
            return;
        }

        $resource = $this->repository->getClassResourceFor($class->getName());
        $ctor = $resource['constructor'] ?? [];
        $existing = is_array($ctor) && is_array($ctor['params'] ?? null)
            ? $ctor['params']
            : [];
        $suppliedList = is_array($supplied) ? $supplied : [$supplied];
        $this->repository->addClassResource(
            $class->getName(),
            'constructor',
            ['on' => '__constructor', 'params' => $suppliedList + $existing],
        );
    }

    /**
     * @param array<int, ReflectionParameter> $availableParams
     * @param array<int|string, mixed> $suppliedParameters
     * @param array<string, string> $parameterAttribute
     * @return array{
     *   availableParams: array<int, ReflectionParameter>,
     *   processed: array<string, mixed>,
     *   availableSupply: array<int|string, mixed>,
     *   sort: array<string, int>
     * }
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
    ): array {
        $processed = [];
        $paramsLeft = [];
        $sort = [];

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();
            $sort[$paramName] = $key;

            if ($param->isVariadic()) {
                $paramsLeft[] = $param;

                break;
            }

            $resolvedValue = $this->tryResolveAssociative(
                $reflector,
                $param,
                $type,
                $suppliedParameters,
                $parameterAttribute,
                $processed,
            );

            if ($resolvedValue !== $this->stdClass) {
                $processed[$paramName] = $resolvedValue;

                continue;
            }

            $paramsLeft[] = $param;
        }

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => array_diff_key($suppliedParameters, $processed),
            'sort' => $sort,
        ];
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed>|mixed $supplied
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied,
    ): object {
        $this->appendConstructorSupplied($class, $type, $supplied);

        return $this->classResolver->resolveClassInstance($class);
    }

    /**
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveIndividualAttribute(
        ReflectionParameter $param,
        string $attributeValue,
    ): mixed {
        $definition = $this->resolveByDefinitionType($attributeValue, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        if (function_exists($attributeValue)) {
            $reflectionFn = ReflectionResource::getFunctionReflection($attributeValue);

            return $attributeValue(...$this->resolve($reflectionFn, [], 'constructor'));
        }

        return $this->stdClass;
    }

    /**
     * @param array<int, ReflectionAttribute<\Infocyph\InterMix\DI\Attribute\Infuse>> $attributes
     * @return array<string, string>
     */
    private function resolveMethodAttributes(array $attributes): array
    {
        $first = $attributes[0] ?? null;
        if ($first === null || $first->getArguments() === []) {
            return [];
        }

        $instance = $first->newInstance();
        $arguments = $instance->getMethodArguments();
        if (!is_array($arguments)) {
            return [];
        }

        $normalized = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $suppliedParameters
     * @param array<string, string> $parameterAttribute
     * @param array<string, mixed> $processed
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function tryResolveAssociative(
        ReflectionFunctionAbstract $reflector,
        ReflectionParameter $param,
        string $type,
        array $suppliedParameters,
        array $parameterAttribute,
        array $processed,
    ): mixed {
        $paramName = $param->getName();

        $definition = $this->resolveByDefinitionType($paramName, $param);
        if ($definition !== $this->stdClass) {
            return $definition;
        }

        $classReflection = $this->getResolvableReflection($reflector, $param, $type, $processed);
        if ($classReflection) {
            $nameHint = $classReflection->isInterface()
                ? $classReflection->getName()
                : $paramName;

            return $this->resolveClassDependency(
                $classReflection,
                $type,
                $suppliedParameters[$nameHint] ?? $suppliedParameters[$paramName] ?? null,
            );
        }

        if (array_key_exists($paramName, $suppliedParameters)) {
            return $suppliedParameters[$paramName];
        }

        if (isset($parameterAttribute[$paramName])) {
            $resolved = $this->resolveIndividualAttribute($param, $parameterAttribute[$paramName]);
            if ($resolved !== $this->stdClass) {
                return $resolved;
            }
        }

        return $this->stdClass;
    }
}
