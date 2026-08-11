<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use InvalidArgumentException;
use Stringable;

/** @internal */
final class ServiceId
{
    public static function from(mixed $value): string
    {
        if (is_int($value) || $value instanceof Stringable) {
            $value = (string) $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Service ID must be a non-empty string or integer.');
        }

        return $value;
    }
}
