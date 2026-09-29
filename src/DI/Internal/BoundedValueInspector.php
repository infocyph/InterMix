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

        foreach ($value as $key => $item) {
            $reference = ReflectionReference::fromArrayElement($value, $key);
            $referenceId = is_array($item) && $reference instanceof ReflectionReference
                ? $reference->getId()
                : null;
            if ($referenceId !== null) {
                if (isset($activeReferences[$referenceId])) {
                    return false;
                }
                $activeReferences[$referenceId] = true;
            }

            $safe = self::inspect(
                $item,
                $depth + 1,
                $visited,
                $activeReferences,
                $maxDepth,
                $maxValues,
            );

            if ($referenceId !== null) {
                unset($activeReferences[$referenceId]);
            }
            if (!$safe) {
                return false;
            }
        }

        return true;
    }
}
