<?php

// src/Fence/Fence.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Exceptions\RequirementException;
use InvalidArgumentException;

trait Fence
{
    private static ?array $cachedClasses = null;

    /** @var array{extensions?:string[],classes?:string[]}|null */
    private static ?array $cachedExtensions = null;

    /** @var array<string,int>  dynamic overrides of limits per class */
    private static array $classLimits = [];
    private static int $instanceCount = 0;
    /** @var array<string,object> */
    private static array $instances = [];

    /** @var array<string,bool>  keyed flag cache */
    private static array $keyedCache = [];

    /** @var array<string,int>   default limits (before setLimit()) */
    private static array $limitCache = [];

    /**
     * Resets the internal cache of instances.  This is mostly useful for unit tests.
     */
    final public static function clearInstances(): void
    {
        self::$instanceCount = 0;
        self::$instances = [];
    }

    /**
     * Get the number of instances created.
     *
     * @return int The number of instances created.
     */
    final public static function countInstances(): int
    {
        return self::$instanceCount;
    }

    /**
     * Returns an array of all the instances created so far.
     *
     * The keys of the returned array are the keys used to store the instances,
     * and the values are the instances themselves.
     *
     * @return array<string, Fence>
     */
    final public static function getInstances(): array
    {
        return self::$instances;
    }

    /**
     * Returns an array of the keys used to store the instances.
     *
     * If the class using this trait has `FENCE_KEYED = false`, this will be
     * an array with a single element `__single`.  Otherwise, this will be an
     * array of the strings used as the first argument to `instance()`.
     *
     * @return array
     */
    final public static function getKeys(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Checks if an instance already exists for the given key.
     *
     * If the class is keyed, the key is required and the check is done
     * against that key.  Otherwise, the check is done against the
     * special key '__single'.
     *
     * @param string|null $key
     * @return bool true if an instance exists for the given key
     */
    final public static function hasInstance(?string $key = 'default'): bool
    {
        $slot = self::isKeyed(static::class)
            ? ($key ?? 'default')
            : '__single';

        return isset(self::$instances[$slot]);
    }

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
     * @param array|null $constraints ['extensions'=>[], 'classes'=>[]]
     * @return Fence
     */
    final public static function instance(
        ?string $key = 'default',
        ?array $constraints = null,
    ): static {
        self::checkRequirements($constraints);

        $slot = self::isKeyed(static::class)
            ? ($key ?? 'default')
            : '__single';

        // Fast path – instance already exists
        if (isset(self::$instances[$slot])) {
            return self::$instances[$slot];
        }

        if (self::$instanceCount >= self::getLimit(static::class)) {
            throw new LimitExceededException(
                'Instance limit of '.self::getLimit(static::class).' exceeded for '.static::class
            );
        }

        self::$instanceCount++;
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

    /**
     * Verifies that the class instance can be created given the requirements.
     *
     * @param array<string,array<string>> $c An array with 'extensions' and/or 'classes' keys.
     *   The values are arrays of names of extensions and classes that must be present.
     */
    private static function checkRequirements(?array $c): void
    {
        if (!$c || ($c['extensions'] ?? []) === [] && ($c['classes'] ?? []) === []) {
            return;
        }

        self::$cachedExtensions ??= get_loaded_extensions();
        self::$cachedClasses ??= get_declared_classes();

        $missingE = array_diff((array)($c['extensions'] ?? []), self::$cachedExtensions);
        $missingC = array_diff((array)($c['classes'] ?? []), self::$cachedClasses);

        if ($missingE || $missingC) {
            $parts = [];
            if ($missingE) {
                $parts[] = 'Extensions not loaded: ' . implode(', ', $missingE);
            }
            if ($missingC) {
                $parts[] = 'Classes not found: ' . implode(', ', $missingC);
            }
            throw new RequirementException('Requirements not met: ' . implode('; ', $parts));
        }
    }

    /**
     * Returns the instance limit for the given class.
     *
     * If `$classLimits[$cls]` is set, it takes precedence. Otherwise, if
     * the class defines `FENCE_LIMIT`, that value is used. Otherwise,
     * the limit is infinite (`PHP_INT_MAX`).
     */
    private static function getLimit(string $cls): int
    {
        return self::$classLimits[$cls]
            ??= defined("$cls::FENCE_LIMIT")
            ? (int) constant("$cls::FENCE_LIMIT")
            : PHP_INT_MAX;
    }

    /**
     * Check if the given class is keyed.
     *
     * If the class defines a constant `FENCE_KEYED`, its value is used.
     * Otherwise, the default is to be keyed (`true`).
     *
     * @param string $cls The class to check.
     * @return bool Whether the class is keyed.
     */
    private static function isKeyed(string $cls): bool
    {
        return self::$keyedCache[$cls]
            ??= !defined("$cls::FENCE_KEYED") || (bool) constant("$cls::FENCE_KEYED");
    }
}
