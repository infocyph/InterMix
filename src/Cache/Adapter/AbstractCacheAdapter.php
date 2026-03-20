<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

abstract class AbstractCacheAdapter implements CacheItemPoolInterface, Countable, InternalCachePoolInterface
{
    /** @var array<string, CacheItemInterface> */
    protected array $deferred = [];

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
