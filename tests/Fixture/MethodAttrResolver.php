<?php

declare(strict_types=1);

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
    ): null {
        fwrite(
            STDERR,
            '[TEST] '
            . $attributeInstance->message
            . ' on '
            . $target->getName()
            . ' in '
            . $container::class
            . "\n"
        );

        return null;
    }
}
