<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use ReflectionReference;

/** @internal */
final class BoundedValueInspector
{
    public const int DEFAULT_MAX_DEPTH = 64;

    public const int DEFAULT_MAX_VALUES = 100_000;

    public static function isScalarNullArray(
        mixed $value,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        int $maxValues = self::DEFAULT_MAX_VALUES,
    ): bool {
        if ($maxDepth < 0 || $maxValues < 1) {
            return false;
        }

        $visited = 0;
        $activeReferences = [];

        return self::inspect($value, 0, $visited, $activeReferences, $maxDepth, $maxValues);
    }

    /** @param array<string, true> $activeReferences */
    private static function inspect(
        mixed $value,
        int $depth,
        int &$visited,
        array &$activeReferences,
        int $maxDepth,
        int $maxValues,
    ): bool {
        if (++$visited > $maxValues) {
            return false;
        }
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value) || $depth > $maxDepth) {
            return false;
        }

        return array_all(
            $value,
            function (mixed $item, int|string $key) use (
                $value,
                $depth,
                &$visited,
                &$activeReferences,
                $maxDepth,
                $maxValues,
            ): bool {
                return self::inspectElement(
                    $value,
                    $key,
                    $item,
                    $depth,
                    $visited,
                    $activeReferences,
                    $maxDepth,
                    $maxValues,
                );
            },
        );
    }

    /**
     * @param array<array-key, mixed> $container
     * @param array<string, true> $activeReferences
     */
    private static function inspectElement(
        array $container,
        int|string $key,
        mixed $item,
        int $depth,
        int &$visited,
        array &$activeReferences,
        int $maxDepth,
        int $maxValues,
    ): bool {
        $referenceId = self::referenceId($container, $key, $item);
        if ($referenceId === null) {
            return self::inspect($item, $depth + 1, $visited, $activeReferences, $maxDepth, $maxValues);
        }
        if (isset($activeReferences[$referenceId])) {
            return false;
        }

        $activeReferences[$referenceId] = true;
        $safe = self::inspect($item, $depth + 1, $visited, $activeReferences, $maxDepth, $maxValues);
        unset($activeReferences[$referenceId]);

        return $safe;
    }

    /** @param array<array-key, mixed> $container */
    private static function referenceId(array $container, int|string $key, mixed $item): ?string
    {
        if (!is_array($item)) {
            return null;
        }

        return ReflectionReference::fromArrayElement($container, $key)?->getId();
    }
}
