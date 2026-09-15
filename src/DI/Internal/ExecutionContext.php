<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Fiber;
use Throwable;
use WeakMap;
use WeakReference;

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

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, string>|null */
    private static ?WeakMap $fiberCarrierIds = null;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private static ?Fiber $lastFiber = null;

    private static ?string $lastFiberCarrierId = null;

    private static ?string $lastObjectCarrierId = null;

    /** @var WeakReference<object>|null */
    private static ?WeakReference $lastObjectCarrierReference = null;

    private static int $nextFiberCarrierId = 0;

    private static int $nextObjectCarrierId = 0;

    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $objectCarrierIds = null;

    public static function id(): ?string
    {
        $fiber = Fiber::getCurrent();
        if ($fiber instanceof Fiber) {
            return self::fiberCarrierId($fiber);
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

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private static function fiberCarrierId(Fiber $fiber): string
    {
        if (self::$lastFiber === $fiber && self::$lastFiberCarrierId !== null) {
            return self::$lastFiberCarrierId;
        }

        $ids = self::$fiberCarrierIds;
        $previous = self::$lastFiber;
        $previousId = self::$lastFiberCarrierId;
        if ($previous instanceof Fiber && $previousId !== null && !$previous->isTerminated()) {
            if (!$ids instanceof WeakMap) {
                $ids = new WeakMap();
                self::$fiberCarrierIds = $ids;
            }
            $ids[$previous] = $previousId;
        }

        $id = $ids instanceof WeakMap ? ($ids[$fiber] ?? null) : null;
        if (is_string($id)) {
            unset($ids[$fiber]);
        } else {
            $id = 'fiber:' . ++self::$nextFiberCarrierId;
        }

        self::$lastFiber = $fiber;
        self::$lastFiberCarrierId = $id;

        return $id;
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
        $lastCarrier = self::$lastObjectCarrierReference?->get();
        if ($lastCarrier === $carrier && self::$lastObjectCarrierId !== null) {
            return self::$lastObjectCarrierId;
        }

        $ids = self::$objectCarrierIds ??= new WeakMap();
        $id = $ids[$carrier] ?? null;
        if (!is_string($id)) {
            $id = $prefix . ++self::$nextObjectCarrierId;
            $ids[$carrier] = $id;
        }

        self::$lastObjectCarrierReference = WeakReference::create($carrier);
        self::$lastObjectCarrierId = $id;

        return $id;
    }
}
