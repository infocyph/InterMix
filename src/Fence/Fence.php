<?php

// src/Fence/Fence.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Exceptions\RequirementException;
use InvalidArgumentException;

trait Fence
{
    /** @var array<string,object> */
    private static array $instances = [];

    /** @var array<string,int>  dynamic overrides of limits per class */
    private static array $classLimits = [];

    /** @var array{extensions?:string[],classes?:string[]} */
    private static ?array $cachedExtensions = null;
    private static ?array $cachedClasses    = null;

    /**
     * Get or create an instance.
     *
     * If the class using this trait defines:
     *
     *   public const FENCE_KEYED = true|false;
     *   public const FENCE_LIMIT = <int>;
     *
     * those values are honoured.  Otherwise defaults are keyed=true, limit=∞.
     *
     * @param string|null $key
     * @param array|null  $constraints  ['extensions'=>[], 'classes'=>[]]
     * @throws RequirementException
     * @throws LimitExceededException
     */
    final public static function instance(
        ?string $key = 'default',
        ?array  $constraints = null
    ): static {
        self::checkRequirements($constraints);

        $slot  = self::isKeyed(static::class)
            ? ($key ?? 'default')
            : '__single';

        $limit = self::getLimit(static::class);

        if (! isset(self::$instances[$slot])
            && count(self::$instances) >= $limit
        ) {
            throw new LimitExceededException(
                "Instance limit of {$limit} exceeded for " . static::class
            );
        }

        return self::$instances[$slot]
            ??= new static();
    }

    /**
     * Override the limit for this class at runtime.
     *
     * @param int $n must be >= 1
     */
    final public static function setLimit(int $n): void
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Limit must be at least 1');
        }
        self::$classLimits[static::class] = $n;
    }

    final public static function hasInstance(?string $key = 'default'): bool
    {
        $slot = self::isKeyed(static::class)
            ? ($key ?? 'default')
            : '__single';

        return isset(self::$instances[$slot]);
    }

    final public static function getInstances(): array
    {
        return self::$instances;
    }

    final public static function getKeys(): array
    {
        return array_keys(self::$instances);
    }

    final public static function clearInstances(): void
    {
        self::$instances = [];
    }

    final public static function countInstances(): int
    {
        return count(self::$instances);
    }

    /** ===== Internals ===== */

    private static function checkRequirements(?array $c): void
    {
        if (! $c) {
            return;
        }

        self::$cachedExtensions ??= get_loaded_extensions();
        self::$cachedClasses    ??= get_declared_classes();

        $missingE = array_diff((array)($c['extensions'] ?? []), self::$cachedExtensions);
        $missingC = array_diff((array)($c['classes']    ?? []), self::$cachedClasses);

        if ($missingE || $missingC) {
            $parts = [];
            if ($missingE) {
                $parts[] = 'Extensions not loaded: '.implode(', ', $missingE);
            }
            if ($missingC) {
                $parts[] = 'Classes not found: '.implode(', ', $missingC);
            }
            throw new RequirementException('Requirements not met: '.implode('; ', $parts));
        }
    }

    private static function isKeyed(string $cls): bool
    {
        return !defined("$cls::FENCE_KEYED") || (bool)constant("$cls::FENCE_KEYED");
    }

    private static function getLimit(string $cls): int
    {
        return self::$classLimits[$cls] ?? (defined("$cls::FENCE_LIMIT")
            ? (int) constant("$cls::FENCE_LIMIT")
            : PHP_INT_MAX);
    }
}
