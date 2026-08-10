<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionParameter;

trait ResolvesParameterAttributes
{
    /**
     * @return array{isResolved:bool,inject:bool,value:mixed}
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    private function resolveParameterAttribute(ReflectionParameter $param): array
    {
        $plan = $this->getParameterAttributePlan($param);
        $inject = $plan['inject'];
        $firstInject = $inject[0] ?? null;
        if ($firstInject !== null && $firstInject->getArguments() !== []) {
            $resolved = $this->classResolver->resolveInject($firstInject->newInstance());

            return [
                'isResolved' => true,
                'inject' => $resolved !== AttributeResolution::Unresolved,
                'value' => $resolved,
            ];
        }

        $registry = $this->repository->attributeRegistry();
        $injectVal = AttributeResolution::Unresolved;
        $handled = false;

        foreach ($plan['all'] as $raw) {
            $attrObj = $raw->newInstance();

            if (!$registry->has($attrObj::class)) {
                continue;
            }

            $handled = true;
            $val = $registry->resolve($attrObj, $param);

            if ($injectVal === AttributeResolution::Unresolved && $val !== AttributeResolution::Unresolved) {
                $injectVal = $val;
            }
        }

        return [
            'isResolved' => $handled,
            'inject' => $injectVal !== AttributeResolution::Unresolved,
            'value' => $injectVal,
        ];
    }
}
