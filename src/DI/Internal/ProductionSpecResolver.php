<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class ProductionSpecResolver
{
    /**
     * @param string|array<array-key, mixed>|Closure|callable $spec
     * @param array<int|string, mixed> $parameters
     */
    public static function resolveDynamic(Container $dynamic, string|Closure|callable|array $spec, array $parameters): mixed
    {
        if (is_array($spec) && (count($spec) !== 2 || !array_is_list($spec))) {
            return $dynamic->resolveNow($spec, $parameters);
        }
        if (is_array($spec) && !is_string($spec[0])) {
            if (!is_callable($spec)) {
                throw new ContainerException(
                    "Unknown callable spec for 'array'. Expected closure/callable, 'class@method', 'class::method', [class,method], class, or function.",
                );
            }

            return $dynamic->resolveNow(Closure::fromCallable($spec), $parameters);
        }

        return $dynamic->resolveNow($spec, $parameters);
    }
}
