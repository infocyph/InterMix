<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Infocyph\InterMix\DI\Container;
use Reflector;

/**
 * Every attribute-resolver pair must implement this.
 *
 * The container guarantees that:
 *   • $attributeInstance  is an *instance* of the attribute class
 *   • $target            is the Reflector (parameter / property / method)
 *   • return `null`      → “I choose not to resolve this attribute”
 *   • return anything else → injected value
 */
interface AttributeResolverInterface
{
    public function resolve(
        object $attributeInstance,
        Reflector $target,
        Container $container,
    ): mixed;
}
