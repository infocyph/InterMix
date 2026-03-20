<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use Countable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

/**
 * Unified cache interface combining PSR-6, PSR-16, and additional functionality.
 *
 * This interface extends multiple cache standards to provide a comprehensive
 * caching solution:
 * - PSR-6 CacheItemPoolInterface: Advanced caching with items and metadata
 * - PSR-16 SimpleCacheInterface: Simplified caching operations
 * - ArrayAccess: Array-like access to cache entries
 * - Countable: Count cache entries
 *
 * Implementations of this interface provide a unified API for both simple
 * and advanced caching use cases, supporting features like tagged cache
 * invalidation, cache stampede protection, and multiple storage adapters.
 */
interface CacheInterface extends CacheItemPoolInterface, SimpleCacheInterface, ArrayAccess, Countable
{
}
