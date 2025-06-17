<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

use InvalidArgumentException;

use function Opis\Closure\{serialize as oc_serialize, unserialize as oc_unserialize};

final class ValueSerializer
{
    /** @var array<string,array{wrap:callable,restore:callable}> */
    private static array $resourceHandlers = [];

    /**
     * Registers a handler for a specific resource type.
     *
     * The two callables provided are:
     *  1. `wrapFn`: takes a resource of type `$type` and returns an array
     *     (or other serializable value) that represents the resource.
     *  2. `restoreFn`: takes the array (or other serializable value) returned
     *     by `wrapFn` and returns a resource of type `$type`.
     *
     * @param string $type The type of resource this handler is for.
     * @param callable $wrapFn The callable that wraps the resource.
     * @param callable $restoreFn The callable that restores the resource.
     *
     * @throws InvalidArgumentException If a handler for `$type` already exists.
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
     * Determines if a given string is a serialized Opis closure.
     *
     * This method checks if the provided string represents a serialized
     * Opis closure by looking for specific patterns associated with
     * Opis closures.
     *
     * @param string $str The string to check.
     *
     * @return bool True if the string is a serialized Opis closure, false otherwise.
     */
    public static function isSerializedClosure(string $str): bool
    {
        if (!str_contains($str, 'Opis\\Closure')) {
            return false;
        }
        return (bool) preg_match(
            '/^(?:C:\d+:"Opis\\\\Closure\\\\SerializableClosure|O:\d+:"Opis\\\\Closure\\\\Box"|O:\d+:"Opis\\\\Closure\\\\Serializable")/',
            $str
        );
    }

    /**
     * Serializes a given value into a string.
     *
     * This method takes a value, wraps any resources it contains using registered
     * resource handlers, and serializes it into a string using Opis Closure's
     * serialize function.
     *
     * @param mixed $value The value to be serialized, which may contain resources.
     *
     * @return string The serialized string representation of the value.
     *
     * @throws InvalidArgumentException If a resource type has no registered handler.
     */
    public static function serialize(mixed $value): string
    {
        return oc_serialize(self::wrapRecursive($value));
    }


    /**
     * Unserializes a given string into its original value.
     *
     * This method takes a serialized string and converts it back into its
     * original value. It first unserializes the string using Opis Closure's
     * unserialize function, then recursively unwraps any wrapped resources
     * within the resulting value using registered resource handlers.
     *
     * @param string $blob The serialized string to be converted back to its original form.
     *
     * @return mixed The original value, with any resources restored.
     */
    public static function unserialize(string $blob): mixed
    {
        return self::unwrapRecursive(oc_unserialize($blob));
    }


    /**
     * Wraps resources within a given value.
     *
     * This method acts as a public interface to recursively wrap
     * resources found within the provided value using registered
     * resource handlers.
     *
     * @param mixed $value The value to be wrapped, which may contain resources.
     *
     * @return mixed The value with any resources wrapped, or the original value if no resources are found.
     */
    public static function wrap(mixed $value): mixed
    {
        return self::wrapRecursive($value);
    }


    /**
     * Reverse {@see wrap} by recursively unwrapping values that were wrapped by
     * {@see wrap}. This method is similar to {@see unserialize}, but it does not
     * involve serialisation.
     *
     * @param mixed $resource A value that may contain wrapped resources.
     *
     * @return mixed The same value with any wrapped resources restored.
     */
    public static function unwrap(mixed $resource): mixed
    {
        return self::unwrapRecursive($resource);
    }

    /**
     * Clear all registered resource handlers.
     *
     * Use this method to reset the state of ValueSerializer in test cases,
     * or when you want to ensure that no resource handlers are registered.
     *
     * @return void
     */
    public static function clearResourceHandlers(): void
    {
        self::$resourceHandlers = [];
    }

    /**
     * Recursively wraps resources within a given value.
     *
     * This method checks if the provided value is a resource. If so,
     * it retrieves the appropriate handler for the resource type and
     * uses it to wrap the resource. The wrapped resource is returned
     * as an associative array containing a flag, the resource type,
     * and the wrapped data.
     *
     * If the value is an array, the method recursively processes each
     * element in the array.
     *
     * @param mixed $resource The value to be wrapped, which may contain resources.
     *
     * @return mixed The value with any resources wrapped, or the original value if no resources are found.
     *
     * @throws InvalidArgumentException If no handler is registered for a resource type.
     */
    private static function wrapRecursive(mixed $resource): mixed
    {
        if (is_resource($resource)) {
            $type = get_resource_type($resource);
            $arr = self::$resourceHandlers[$type] ?? null;
            if (!$arr) {
                throw new InvalidArgumentException("No handler for resource type '$type'");
            }
            return [
                '__wrapped_resource' => true,
                'type' => $type,
                'data' => ($arr['wrap'])($resource),
            ];
        }

        if (is_array($resource)) {
            foreach ($resource as $key => $value) {
                $resource[$key] = self::wrapRecursive($value);
            }
        }
        return $resource;
    }

    /**
     * Reverse {@see wrapRecursive} by recursively unwrapping values
     * that were wrapped by {@see wrapRecursive}.
     *
     * @param mixed $resource A value that may contain wrapped resources.
     *
     * @return mixed The same value with any wrapped resources restored.
     */
    private static function unwrapRecursive(mixed $resource): mixed
    {
        if (
            is_array($resource) &&
            ($resource['__wrapped_resource'] ?? false) &&
            isset(self::$resourceHandlers[$resource['type']])
        ) {
            return (self::$resourceHandlers[$resource['type']]['restore'])($resource['data']);
        }

        if (is_array($resource)) {
            foreach ($resource as $key => $item) {
                $resource[$key] = self::unwrapRecursive($item);
            }
        }
        return $resource;
    }
}
