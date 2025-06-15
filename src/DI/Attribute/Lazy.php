<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

/**
 * Marks a definition / property / method / class for lazy initialisation.
 * The container will wrap the target in a DeferredInitializer until first use.
 */
#[Attribute(
    Attribute::TARGET_FUNCTION
    | Attribute::TARGET_METHOD
    | Attribute::TARGET_PROPERTY
    | Attribute::TARGET_CLASS
)]
final class Lazy
{
}
