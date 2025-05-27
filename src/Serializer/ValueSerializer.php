<?php

namespace Infocyph\InterMix\Serializer;

use InvalidArgumentException;

use function Opis\Closure\{serialize as oc_serialize, unserialize as oc_unserialize};

class ValueSerializer
{
    /** @var array<string,array{wrap:callable,restore:callable}> */
    private static array $resourceHandlers = [];

    /**
     * Register a handler for any resource type.
     *
     * @param string $type get_resource_type()
     * @param callable $wrapFn fn(resource): mixed  — metadata to store
     * @param callable $restoreFn fn(mixed): resource   — rebuild resource
     */
    public static function registerResourceHandler(
        string $type,
        callable $wrapFn,
        callable $restoreFn,
    ): void {
        self::$resourceHandlers[$type] = [
            'wrap' => $wrapFn,
            'restore' => $restoreFn,
        ];
    }


    /**
     * Serialize a value, transforming any resources into a serializable form.
     *
     * @param mixed $value The value to serialize.
     *
     * @return string The serialized form of the value.
     */
    public static function serialize(mixed $value): string
    {
        return oc_serialize(self::wrapRecursive($value));
    }

    /**
     * Unserialize a string containing the serialized form of a value.
     *
     * Reverse the process of {@see serialize()} by deserializing the given string
     * and unwrapping any resources that were previously wrapped.
     *
     * @param string $blob The serialized string to unserialize.
     * @return mixed The unserialized value, which may contain resources.
     */
    public static function unserialize(string $blob): mixed
    {
        return self::unwrapRecursive(oc_unserialize($blob));
    }

    /**
     * Wrap a value, transforming any resources within it into a serializable form.
     *
     * This function acts as a wrapper around {@see wrapRecursive()}, providing
     * an entry point for wrapping a given value. Resources are processed using
     * registered handlers to prepare them for serialization, while other values
     * are returned unchanged.
     *
     * @param mixed $value The value to wrap, potentially containing resources.
     * @return mixed The wrapped value, with resources transformed for serialization.
     */
    public static function wrap(mixed $value): mixed
    {
        return self::wrapRecursive($value);
    }

    /**
     * Unwrap a value, restoring any resources from their serialized form.
     *
     * This function acts as a wrapper around {@see unwrapRecursive()}, providing
     * an entry point for unwrapping a given value. It reverses the transformation
     * applied by {@see wrap()}, converting serialized resource representations
     * back into their original resource form using registered handlers. Non-resource
     * values are returned unchanged.
     *
     * @param mixed $value The value to unwrap, potentially containing serialized resources.
     * @return mixed The unwrapped value, with resources restored to their original form.
     */
    public static function unwrap(mixed $value): mixed
    {
        return self::unwrapRecursive($value);
    }

    /**
     * Recursively wrap any resources within the given value into a serializable form.
     *
     * This function traverses the input value, wrapping any PHP resource it
     * encounters using registered handlers. The resulting value is returned
     * with resources transformed into arrays containing metadata for serialization.
     * Non-resource values are returned unchanged.
     *
     * @param mixed $value The value to wrap, which may contain resources.
     * @return mixed The wrapped value with resources transformed for serialization.
     * @throws InvalidArgumentException If no handler is registered for a resource type.
     */
    private static function wrapRecursive(mixed $value): mixed
    {
        if (is_resource($value)) {
            $type = get_resource_type($value);
            if (!isset(self::$resourceHandlers[$type])) {
                throw new InvalidArgumentException(
                    "No handler registered for resource type '{$type}'",
                );
            }
            $data = (self::$resourceHandlers[$type]['wrap'])($value);
            return [
                '__wrapped_resource' => true,
                'type' => $type,
                'data' => $data,
            ];
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::wrapRecursive($v);
            }
            return $value;
        }

        return $value;
    }


    /**
     * Recursively unwrap any wrapped resource in the value.
     *
     * @param mixed $value Value to unwrap
     * @return mixed Unwrapped value
     */
    private static function unwrapRecursive(mixed $value): mixed
    {
        if (
            is_array($value) &&
            !empty($value['__wrapped_resource']) &&
            isset(self::$resourceHandlers[$value['type']])
        ) {
            return (self::$resourceHandlers[$value['type']]['restore'])($value['data']);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::unwrapRecursive($v);
            }
            return $value;
        }

        return $value;
    }
}


// ─── Register built-in resource handlers ────────────────────────────────────

// Streams (including non-seekable network sockets)
//ValueSerializer::registerResourceHandler(
//    'stream',
//    function (resource $res): array {
//        $meta = stream_get_meta_data($res);
//        rewind($res);
//        return [
//            'mode'    => $meta['mode'],
//            'content' => stream_get_contents($res),
//        ];
//    },
//    function (array $data): resource {
//        $s = fopen('php://memory', $data['mode']);
//        fwrite($s, $data['content']);
//        rewind($s);
//        return $s;
//    }
//);
//
//// GD images
//ValueSerializer::registerResourceHandler(
//    'gd',
//    function (resource $im): array {
//        ob_start();
//        imagepng($im);
//        $png = ob_get_clean();
//        return ['png' => $png];
//    },
//    function (array $data): resource {
//        return imagecreatefromstring($data['png']);
//    }
//);
