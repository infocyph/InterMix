<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Package-internal access to runtime state that is intentionally absent from the
 * public runtime contract.
 *
 * @internal
 */
final class ContainerAccess extends Container
{
    public static function fork(Container $container, bool $locked = true): Container
    {
        return $container->forkRuntime($locked);
    }

    /** @return array<string, mixed> */
    public static function graph(Container $container, ?string $warmFromId = null, bool $clear = false): array
    {
        return $container->exportGraph($warmFromId, $clear);
    }

    public static function repository(Container $container): Repository
    {
        return $container->repository;
    }

    public static function resolver(Container $container): CompiledCall|InjectedCall|GenericCall
    {
        return $container->getCurrentResolver();
    }
}
