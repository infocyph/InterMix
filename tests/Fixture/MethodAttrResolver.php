<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Reflector;

class MethodAttrResolver implements AttributeResolverInterface
{
    public function resolve(
        object $attributeInstance,
        Reflector $target,
        Container $container
    ): mixed {
        // Log, side-effect, or simply acknowledge
        fwrite(STDERR, "[TEST] {$attributeInstance->message} on {$target->getName()}\n");
        return null;
    }
}
