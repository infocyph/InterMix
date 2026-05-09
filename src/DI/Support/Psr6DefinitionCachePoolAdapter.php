<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

readonly class Psr6DefinitionCachePoolAdapter implements DefinitionCachePoolInterface
{
    public function __construct(
        private CacheItemPoolInterface $pool,
    ) {}

    public function clear(): bool
    {
        return $this->pool->clear();
    }

    public function commit(): bool
    {
        return $this->pool->commit();
    }

    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($this->pool->getItems($keys) as $key => $item) {
            if (is_string($key) && $item instanceof CacheItemInterface) {
                $items[$key] = $item;
            }
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }
}
