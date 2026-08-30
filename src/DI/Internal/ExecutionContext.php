<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Fiber;

/** @internal */
final class ExecutionContext
{
    public static function id(): ?string
    {
        $fiber = Fiber::getCurrent();
        if ($fiber instanceof Fiber) {
            return 'fiber:' . spl_object_id($fiber);
        }

        $id = self::coroutineId('Swoole\\Coroutine');
        if ($id !== null) {
            return 'swoole:' . $id;
        }

        $id = self::coroutineId('OpenSwoole\\Coroutine');
        if ($id !== null) {
            return 'openswoole:' . $id;
        }

        return null;
    }

    private static function coroutineId(string $class): ?int
    {
        if (!class_exists($class, false)) {
            return null;
        }

        $getCid = [$class, 'getCid'];
        if (!is_callable($getCid)) {
            return null;
        }

        $id = $getCid();

        return is_int($id) && $id >= 0 ? $id : null;
    }
}
