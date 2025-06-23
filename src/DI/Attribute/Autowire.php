<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

/**
 * Exact alias of {@see Infuse}.  Declared in its own file so that
 * Composer’s autoloader can always find the class requested by PHP’s
 * attribute reflection logic.
 *
 * @inheritDoc
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Autowire extends Infuse
{
    /* inherits everything from Infuse */
}
