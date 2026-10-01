<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Package-internal access to runtime state that is intentionally absent from the
 * public runtime contract.
 *
 * @internal
 */
final class ContainerAccess extends Container
{
    public static function repository(Container $container): Repository
    {
        return $container->repository;
    }
}
