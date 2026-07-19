<?php

// src/Fence/Fence.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Exceptions\RequirementException;
use InvalidArgumentException;

trait Fence
{
    private const int REQUIREMENT_CACHE_LIMIT = 256;

    private const string SINGLE_SLOT = '__single';

    /** @var array<string, bool> */
    private static array $classExistsCache = [];

    /** @var array<string, bool> */
    private static array $extensionExistsCache = [];

    /** @var array<string, object> */
    private static array $instances = [];

    private static ?int $limitOverride = null;

    /**
     * Resets the internal cache of instances.  This is mostly useful for unit tests.
     */
    final public static function clearInstances(): void
    {
        self::$instances = [];
    }

    /**
     * Get the number of instances created.
     *
     * @return int The number of instances created.
     */
    final public static function countInstances(): int
    {
        return count(self::$instances);
    }

    /**
     * Returns an array of all the instances created so far.
     *
     * The keys of the returned array are the keys used to store the instances,
     * and the values are the instances themselves.
     *
     * @return array<string, object>
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
     */
    /**
     * @return array<int, string>
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
     * @return bool true if an instance exists for the given key
     */
    final public static function hasInstance(?string $key = 'default'): bool
    {
        return isset(self::$instances[self::slotFor($key)]);
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
     * @param array{extensions?: array<int, string>, classes?: array<int, string>}|null $constraints ['extensions'=>[], 'classes'=>[]]
     */
    final public static function instance(
        ?string $key = 'default',
        ?array $constraints = null,
    ): static {
        $slot = self::slotFor($key);

        // Fast path – instance already exists
        if (isset(self::$instances[$slot])) {
            /** @var static $instance */
            $instance = self::$instances[$slot];

            return $instance;
        }

        self::checkRequirements($constraints);

        $limit = self::getLimit();
        if (count(self::$instances) >= $limit) {
            throw new LimitExceededException(
                'Instance limit of ' . $limit . ' exceeded for ' . static::class,
            );
        }

        $created = new static();
        self::$instances[$slot] = $created;

        return $created;
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
        self::$limitOverride = $n;
    }

    /**
     * Verifies that the class instance can be created given the requirements.
     *
     * @param array{extensions?: array<int, string>, classes?: array<int, string>}|null $c
     *                                                                                     The values are arrays of names of extensions and classes that must be present.
     */
    private static function checkRequirements(?array $c): void
    {
        if ($c === null || (($c['extensions'] ?? []) === [] && ($c['classes'] ?? []) === [])) {
            return;
        }

        $missingE = self::findMissingExtensions((array) ($c['extensions'] ?? []));
        $missingC = self::findMissingClasses((array) ($c['classes'] ?? []));

        if ($missingE === [] && $missingC === []) {
            return;
        }

        throw new RequirementException(
            'Requirements not met: ' . self::formatMissingRequirements($missingE, $missingC),
        );
    }

    /**
     * @param array<string, bool> $cache
     */
    private static function evictRequirementCacheEntryIfNeeded(array &$cache): void
    {
        if (count($cache) >= self::REQUIREMENT_CACHE_LIMIT) {
            unset($cache[array_key_first($cache)]);
        }
    }

    /**
     * @param array<int, string> $required
     * @return array<int, string>
     */
    private static function findMissingClasses(array $required): array
    {
        $missing = [];

        foreach ($required as $class) {
            if ($class === '') {
                continue;
            }

            if (!isset(self::$classExistsCache[$class]) && class_exists($class)) {
                self::evictRequirementCacheEntryIfNeeded(self::$classExistsCache);
                self::$classExistsCache[$class] = true;
            }
            if (!isset(self::$classExistsCache[$class])) {
                $missing[] = $class;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $required
     * @return array<int, string>
     */
    private static function findMissingExtensions(array $required): array
    {
        $missing = [];

        foreach ($required as $extension) {
            if ($extension === '') {
                continue;
            }

            $cacheKey = strtolower($extension);
            if (!isset(self::$extensionExistsCache[$cacheKey]) && extension_loaded($extension)) {
                self::evictRequirementCacheEntryIfNeeded(self::$extensionExistsCache);
                self::$extensionExistsCache[$cacheKey] = true;
            }
            if (!isset(self::$extensionExistsCache[$cacheKey])) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $missingE
     * @param array<int, string> $missingC
     */
    private static function formatMissingRequirements(array $missingE, array $missingC): string
    {
        $parts = [];
        if ($missingE !== []) {
            $parts[] = 'Extensions not loaded: ' . implode(', ', $missingE);
        }
        if ($missingC !== []) {
            $parts[] = 'Classes not found: ' . implode(', ', $missingC);
        }

        return implode('; ', $parts);
    }

    /**
     * Returns the instance limit for the current class.
     */
    private static function getLimit(): int
    {
        if (self::$limitOverride !== null) {
            return self::$limitOverride;
        }

        $className = static::class;
        if (!defined("$className::FENCE_LIMIT")) {
            return PHP_INT_MAX;
        }

        return (int) constant("$className::FENCE_LIMIT");
    }

    private static function isKeyed(): bool
    {
        $className = static::class;
        if (defined("$className::FENCE_KEYED")) {
            return (bool) constant("$className::FENCE_KEYED");
        }

        return true;
    }

    private static function slotFor(?string $key): string
    {
        return self::isKeyed()
            ? ($key ?? 'default')
            : self::SINGLE_SLOT;
    }
}
