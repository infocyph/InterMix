<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

use InvalidArgumentException;

use function Opis\Closure\{serialize as oc_serialize, unserialize as oc_unserialize};

final class ValueSerializer
{
    /** @var array<string,array{wrap:callable,restore:callable}> */
    private static array $resourceHandlers = [];

    /* ---------- resource handler registration -------------------- */

    public static function registerResourceHandler(
        string   $type,
        callable $wrapFn,    // fn(resource): array<string,mixed>
        callable $restoreFn  // fn(array): resource
    ): void {
        self::$resourceHandlers[$type] = [
            'wrap'    => $wrapFn,
            'restore' => $restoreFn,
        ];
    }

    /* ---------- public helpers ----------------------------------- */

    /** Serialise any value (resources wrapped automatically). */
    public static function serialize(mixed $value): string
    {
        return oc_serialize(self::wrapRecursive($value));
    }

    /** Unserialise and restore wrapped resources. */
    public static function unserialize(string $blob): mixed
    {
        return self::unwrapRecursive(oc_unserialize($blob));
    }

    /** Wrap resources without serialising (useful inside CacheItem::set). */
    public static function wrap(mixed $value): mixed
    {
        return self::wrapRecursive($value);
    }

    /** Undo wrap(). */
    public static function unwrap(mixed $value): mixed
    {
        return self::unwrapRecursive($value);
    }

    /* ---------- internals ---------------------------------------- */

    private static function wrapRecursive(mixed $v): mixed
    {
        if (is_resource($v)) {
            $type = get_resource_type($v);
            $h    = self::$resourceHandlers[$type] ?? null;
            if (!$h) {
                throw new InvalidArgumentException("No handler for resource type '$type'");
            }
            return [
                '__wrapped_resource' => true,
                'type'               => $type,
                'data'               => ($h['wrap'])($v),
            ];
        }

        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::wrapRecursive($x);
            }
        }
        return $v;
    }

    private static function unwrapRecursive(mixed $v): mixed
    {
        if (
            is_array($v) &&
            ($v['__wrapped_resource'] ?? false) &&
            isset(self::$resourceHandlers[$v['type']])
        ) {
            return (self::$resourceHandlers[$v['type']]['restore'])($v['data']);
        }

        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::unwrapRecursive($x);
            }
        }
        return $v;
    }
}
