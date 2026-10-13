<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use InvalidArgumentException;

/**
 * An explicit service argument used by a declarative factory definition.
 */
final readonly class ServiceReference
{
    /**
     * Represent an explicit dependency lookup rather than a literal string argument.
     */
    public function __construct(public string $id)
    {
        if ($this->id === '') {
            throw new InvalidArgumentException('A service reference ID cannot be empty.');
        }
    }
}
