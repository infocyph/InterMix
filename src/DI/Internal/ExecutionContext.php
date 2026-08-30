<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Fiber;

/** @internal */
final class ExecutionContext
{
    public static function id(): ?string
    {
        if (class_exists(\Swoole\Coroutine::class, false)) {
            $id = \Swoole\Coroutine::getCid();
            if ($id >= 0) {
                return 'swoole:' . $id;
            }
        }

        if (class_exists(\OpenSwoole\Coroutine::class, false)) {
            $id = \OpenSwoole\Coroutine::getCid();
            if ($id >= 0) {
                return 'openswoole:' . $id;
            }
        }

        $fiber = Fiber::getCurrent();

        return $fiber instanceof Fiber ? 'fiber:' . spl_object_id($fiber) : null;
    }
}
