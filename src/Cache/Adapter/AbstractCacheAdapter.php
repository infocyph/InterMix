<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Abstract base class for cache adapter implementations.
 *
 * This class provides a foundation for building PSR-6 and PSR-16 compliant
 * cache adapters. It implements common functionality like deferred item management
 * and provides default implementations for several cache pool interface methods.
 *
 * Adapters extending this class must implement the abstract methods required
 * for their specific storage mechanism while inheriting common cache operations.
 */
abstract class AbstractCacheAdapter implements CacheItemPoolInterface, Countable, InternalCachePoolInterface
{
    /** @var array<string, CacheItemInterface> */
    protected array $deferred = [];

    /**
     * Determines if this adapter supports the given cache item.
     *
     * @param CacheItemInterface $item The cache item to check.
     * @return bool True if the adapter supports this item type.
     */
    abstract protected function supportsItem(CacheItemInterface $item): bool;

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $key => $item) {
            $ok = $ok && $this->save($item);
            unset($this->deferred[$key]);
        }
        return $ok;
    }

    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function internalPersist(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function internalQueue(CacheItemInterface $item): bool
    {
        return $this->saveDeferred($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }
}
