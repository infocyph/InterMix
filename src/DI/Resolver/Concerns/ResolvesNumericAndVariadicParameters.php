<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

trait ResolvesNumericAndVariadicParameters
{
    private function processVariadic(
        array $processed,
        array $variadic,
        array $sort,
    ): array {
        $variadicValue = (array) $variadic['value'];
        if (isset($variadicValue[0])) {
            uksort($processed, static fn($a, $b) => $sort[$a] <=> $sort[$b]);
            $processed = array_values($processed);
            array_push($processed, ...array_values($variadicValue));
            return $processed;
        }
        return array_merge($processed, $variadicValue);
    }
    /**
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

            $processed[$paramName] = match (true) {
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                $param->allowsNull() => null,
                default => throw new ContainerException(
                    "Resolution failed for '$paramName' in "
                    . ($reflector->class ?? $reflector->getName())
                    . "::{$reflector->getShortName()}()",
                ),
            };
        }

        return [
            'processed' => $processed,
            'variadic' => $variadic,
        ];
    }
}
