<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

trait ResolvesNumericAndVariadicParameters
{
    /**
     * @param array<int|string, mixed> $processed
     * @param array{type: ReflectionNamedType|null, value: array<int|string, mixed>|null} $variadic
     * @param array<string, int> $sort
     * @return array<int|string, mixed>
     */
    private function processVariadic(
        array $processed,
        array $variadic,
        array $sort,
    ): array {
        $variadicValue = (array) $variadic['value'];
        if (isset($variadicValue[0])) {
            uksort(
                $processed,
                static fn(int|string $a, int|string $b): int => ($sort[(string) $a] ?? PHP_INT_MAX) <=> ($sort[(string) $b] ?? PHP_INT_MAX),
            );
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadicValue));

            return $processed;
        }

        return array_merge($processed, $variadicValue);
    }

    /**
     * @throws ContainerException
     */
    private function resolveFallbackValue(ReflectionParameter $param, ReflectionFunctionAbstract $reflector): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        $owner = $reflector instanceof ReflectionMethod
            ? $reflector->getDeclaringClass()->getName()
            : $reflector->getName();

        throw new ContainerException(
            "Resolution failed for '{$param->getName()}' in {$owner}::{$reflector->getShortName()}()",
        );
    }

    /**
     * @param array<int, ReflectionParameter> $availableParams
     * @param array<int|string, mixed> $suppliedParameters
     * @return array{
     *   processed: array<int|string, mixed>,
     *   variadic: array{type: ReflectionNamedType|null, value: array<int|string, mixed>|null}
     * }
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveNumericDefaultParameters(
        ReflectionFunctionAbstract $reflector,
        array $availableParams,
        array $suppliedParameters,
        bool $applyAttribute,
    ): array {
        $processed = [];
        $variadic = ['type' => null, 'value' => null];
        $sequential = array_values($suppliedParameters);

        foreach ($availableParams as $key => $param) {
            $paramName = $param->getName();

            if ($param->isVariadic()) {
                $variadic = [
                    'type' => $param->getType() instanceof ReflectionNamedType
                        ? $param->getType()
                        : null,
                    'value' => array_slice($suppliedParameters, $key),
                ];

                break;
            }

            if (array_key_exists($key, $sequential)) {
                $processed[$paramName] = $sequential[$key];

                continue;
            }

            if ($applyAttribute) {
                $data = $this->resolveParameterAttribute($param);
                if ($data['isResolved']) {
                    $data['inject'] && $processed[$paramName] = $data['value'];

                    continue;
                }
            }

            $processed[$paramName] = $this->resolveFallbackValue($param, $reflector);
        }

        return [
            'processed' => $processed,
            'variadic' => $variadic,
        ];
    }
}
