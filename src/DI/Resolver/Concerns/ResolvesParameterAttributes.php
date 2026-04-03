<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver\Concerns;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
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
        $infuse = $plan['infuse'] ?? [];
        if ($infuse && !empty($infuse[0]->getArguments())) {
            /** @var Infuse $infuse */
            $resolved = $this->classResolver->resolveInfuse($infuse[0]->newInstance());

            return [
                'isResolved' => true,
                'inject' => !$resolved instanceof IMStdClass,
                'value' => $resolved,
            ];
        }

        $registry = $this->repository->attributeRegistry();
        $injectVal = null;
        $handled = false;

        foreach (($plan['all'] ?? []) as $raw) {
            $attrObj = $raw->newInstance();

            if (!$registry->has($attrObj::class)) {
                continue;
            }

            $handled = true;
            $val = $registry->resolve($attrObj, $param);

            if ($injectVal === null && $val !== null && !$val instanceof IMStdClass) {
                $injectVal = $val;
            }
        }

        return [
            'isResolved' => $handled,
            'inject' => $injectVal !== null,
            'value' => $injectVal,
        ];
    }
}
