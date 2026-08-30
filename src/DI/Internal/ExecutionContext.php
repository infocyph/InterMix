<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Fiber;

/** @internal */
final class ExecutionContext
{
    private static ?bool $openSwooleAvailable = null;

    private static ?bool $swooleAvailable = null;

    public static function id(): ?string
    {
        $fiber = Fiber::getCurrent();
        if ($fiber instanceof Fiber) {
            return 'fiber:' . spl_object_id($fiber);
        }

        self::$swooleAvailable ??= class_exists(\Swoole\Coroutine::class, false);
        if (self::$swooleAvailable) {
            $id = \Swoole\Coroutine::getCid();
            if ($id >= 0) {
                return 'swoole:' . $id;
            }
        }

        self::$openSwooleAvailable ??= class_exists(\OpenSwoole\Coroutine::class, false);
        if (self::$openSwooleAvailable) {
            $id = \OpenSwoole\Coroutine::getCid();
            if ($id >= 0) {
                return 'openswoole:' . $id;
            }
        }

        return null;
    }
}
