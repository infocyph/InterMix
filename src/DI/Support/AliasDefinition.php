<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use InvalidArgumentException;

/** @internal */
final readonly class AliasDefinition
{
    /**
     * Represent an identifier redirect whose resolution follows the target entry.
     */
    public function __construct(public string $target)
    {
        if ($this->target === '') {
            throw new InvalidArgumentException('An alias target cannot be empty.');
        }
    }
}
