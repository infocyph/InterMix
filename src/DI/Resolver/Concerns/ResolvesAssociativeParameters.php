<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionParameter;

trait ResolvesAssociativeParameters
{
    /**
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
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied,
    ): object {
        if ($type === 'constructor' && $supplied !== null && $class->getConstructor()) {
            $existing = $this->repository->getClassResource()[$class->getName()]['constructor']['params'] ?? [];
            $this->repository->addClassResource(
                $class->getName(),
                'constructor',
                ['on' => '__constructor', 'params' => (array)$supplied + $existing],
            );
        }
        return $this->classResolver->resolve($class)['instance'];
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

    private function resolveMethodAttributes(array $attributes): array
    {
        if (!$attributes || empty($attributes[0]->getArguments())) {
            return [];
        }
        return $attributes[0]->newInstance()->getMethodArguments();
    }

    /**
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
