<?php

namespace Infocyph\InterMix\Exceptions;

use InvalidArgumentException;
use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown when a cache key or argument is invalid.
 */
class CacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException
{
}
