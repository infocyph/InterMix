<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Reflector;

class ExampleAttrResolver implements AttributeResolverInterface
{
    public function resolve(
        object $attributeInstance,
        Reflector $target,
        Container $container
    ): mixed {
        /** @var ExampleAttr $attributeInstance */
        return strtoupper($attributeInstance->value);
    }
}
