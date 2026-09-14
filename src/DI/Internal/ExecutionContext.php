<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Fiber;
use Throwable;
use WeakMap;

/**
 * Detects the current physical execution carrier only.
 *
 * Carrier identity isolates independent Fibers/coroutines; it is deliberately
 * separate from the logical DI scope identity represented by ScopeContext.
 *
 * @internal
 */
final class ExecutionContext
{
    private static ?Closure $coroutineContextResolver = null;

    private static ?Closure $coroutineIdResolver = null;

    private static ?string $coroutinePrefix = null;

    private static bool $coroutineResolverInitialized = false;

    private static int $nextObjectCarrierId = 0;

    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $objectCarrierIds = null;

    public static function id(): ?string
    {
        $fiber = Fiber::getCurrent();
        if ($fiber instanceof Fiber) {
            return self::objectCarrierId($fiber, 'fiber:');
        }

        if (!self::$coroutineResolverInitialized) {
            self::initializeCoroutineResolver();
        }

        $getCid = self::$coroutineIdResolver;
        $prefix = self::$coroutinePrefix;
        if (!$getCid instanceof Closure || $prefix === null) {
            return null;
        }

        $id = $getCid();
        if (!is_int($id) || $id < 0) {
            return null;
        }

        $getContext = self::$coroutineContextResolver;
        if ($getContext instanceof Closure) {
            try {
                $context = $getContext($id);
            } catch (Throwable) {
                $context = null;
            }

            if (is_object($context)) {
                return self::objectCarrierId($context, $prefix . 'object:');
            }
        }

        return $prefix . $id;
    }

    private static function initializeCoroutineResolver(): void
    {
        self::$coroutineResolverInitialized = true;

        foreach ([
            ['Swoole' . '\\Coroutine', 'swoole:'],
            ['OpenSwoole' . '\\Coroutine', 'openswoole:'],
        ] as [$class, $prefix]) {
            if (!class_exists($class, false)) {
                continue;
            }

            /** @var callable(): mixed $getCid */
            $getCid = [$class, 'getCid'];
            self::$coroutineIdResolver = Closure::fromCallable($getCid);
            self::$coroutinePrefix = $prefix;

            /** @var array{class-string, string} $getContext */
            $getContext = [$class, 'getContext'];
            if (is_callable($getContext)) {
                self::$coroutineContextResolver = Closure::fromCallable($getContext);
            }

            return;
        }
    }

    private static function objectCarrierId(object $carrier, string $prefix): string
    {
        $ids = self::$objectCarrierIds ??= new WeakMap();
        $existing = $ids[$carrier] ?? null;
        if (is_string($existing)) {
            return $existing;
        }

        $id = $prefix . ++self::$nextObjectCarrierId;
        $ids[$carrier] = $id;

        return $id;
    }
}
