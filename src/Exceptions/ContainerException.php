<?php

declare(strict_types=1);

namespace AbmmHasan\OOF\Exceptions;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Exception for the Container.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
}
