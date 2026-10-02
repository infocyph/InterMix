<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Reflector;

class ExampleAttrResolver implements AttributeResolverInterface
{
    public function resolve(
        object $attributeInstance,
        Reflector $target,
        RuntimeContainerInterface $container
    ): mixed {
        /** @var ExampleAttr $attributeInstance */
        $target->getName();
        $container->tracer();

        return strtoupper($attributeInstance->value);
    }
}
