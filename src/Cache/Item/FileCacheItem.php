<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

/**
 * File-based cache item implementation.
 *
 * This class extends AbstractCacheItem to provide cache items specifically
 * designed for use with the FileCacheAdapter. It inherits all
 * standard PSR-6 cache item functionality while being optimized
 * for filesystem-based storage.
 */
final class FileCacheItem extends AbstractCacheItem {}
