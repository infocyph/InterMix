<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Managers\InvocationManager;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Package-internal access to runtime state that is intentionally absent from the
 * public runtime contract.
 *
 * @internal
 */
final class ContainerAccess extends Container
{
    /**
     * Create an isolated runtime through the protected container configuration boundary.
     */
    public static function fork(Container $container, bool $locked = true): Container
    {
        return $container->forkRuntime($locked);
    }

    /**
     * Export the container's dependency trace without exposing mutation APIs publicly.
     *
     * @return array<string, mixed>
     */
    public static function graph(Container $container, ?string $warmFromId = null, bool $clear = false): array
    {
        return $container->exportGraph($warmFromId, $clear);
    }

    /**
     * Expose the container-owned invocation manager to trusted internal resolvers.
     */
    public static function invocationManager(Container $container): InvocationManager
    {
        return $container->invocationManager;
    }

    /**
     * Expose protected repository state to trusted internal collaborators.
     */
    public static function repository(Container $container): Repository
    {
        return $container->repository;
    }

    /**
     * Materialize and expose the container's current invoker for internal dispatch.
     */
    public static function resolver(Container $container): CompiledCall|InjectedCall|GenericCall
    {
        return $container->getCurrentResolver();
    }
}
