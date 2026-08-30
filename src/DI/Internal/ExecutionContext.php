<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Fiber;

/** @internal */
final class ExecutionContext
{
    private static ?Closure $coroutineIdResolver = null;

    private static bool $coroutineResolverInitialized = false;

    public static function id(): ?string
    {
        $fiber = Fiber::getCurrent();
        if ($fiber instanceof Fiber) {
            return 'fiber:' . spl_object_id($fiber);
        }

        if (!self::$coroutineResolverInitialized) {
            self::initializeCoroutineResolver();
        }

        $getCid = self::$coroutineIdResolver;
        if (!$getCid instanceof Closure) {
            return null;
        }

        $id = $getCid();

        return is_int($id) && $id >= 0 ? 'coroutine:' . $id : null;
    }

    private static function initializeCoroutineResolver(): void
    {
        self::$coroutineResolverInitialized = true;

        foreach (['Swoole' . '\\Coroutine', 'OpenSwoole' . '\\Coroutine'] as $class) {
            if (!class_exists($class, false)) {
                continue;
            }

            $getCid = [$class, 'getCid'];
            if (!is_callable($getCid)) {
                continue;
            }

            self::$coroutineIdResolver = Closure::fromCallable($getCid);

            return;
        }
    }
}
