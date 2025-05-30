<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Countable;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Iterator;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class Cachepool implements
    CacheItemPoolInterface,
    ArrayAccess,
    Countable,
    Iterator
{
    /* ---------------------------------------------------------------------
     *  Construction
     * -------------------------------------------------------------------*/
    public function __construct(private readonly CacheItemPoolInterface $adapter)
    {
    }

    /** Static factory for filesystem cache */
    public static function file(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\FileCacheAdapter($namespace, $dir));
    }

    /** Static factory for APCu cache */
    public static function apcu(string $namespace = 'default'): self
    {
        return new self(new Adapter\ApcuCacheAdapter($namespace));
    }

    public static function memcache(
        string $namespace = 'default',
        array  $servers   = [['127.0.0.1',11211,0]],
        ?\Memcached $client = null
    ): self {
        return new self(new Adapter\MemCacheAdapter($namespace, $servers, $client));
    }

    public static function sqlite(
        string $namespace = 'default',
        ?string $file     = null,
    ): self {
        return new self(new Adapter\SqliteCacheAdapter($namespace, $file));
    }

    public static function redis(
        string  $namespace = 'default',
        string  $dsn       = 'redis://127.0.0.1:6379',
        ?\Redis  $client    = null
    ): self {
        return new self(new Adapter\RedisCacheAdapter($namespace, $dsn, $client));
    }


    /* ---------------------------------------------------------------------
     *  PSR-6 pool delegation
     * -------------------------------------------------------------------*/

    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A-Z, a-z, 0-9, _, ., -'
            );
        }
    }
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        return $this->adapter->getItem($key);
    }
    public function getItemsIterator(array $keys = []): iterable
    {
        return $this->adapter->getItems($keys);
    }

    public function getItems(array $keys = []): iterable
    {
        if ($keys === []) {
            return new \EmptyIterator();
        }

        if (method_exists($this->adapter, 'multiFetch')) {
            return $this->adapter->multiFetch($keys);
        }

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->getItem($k);
        }
        return $out;
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->hasItem($key);
    }
    public function clear(): bool
    {
        return $this->adapter->clear();
    }
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->deleteItem($key);
    }
    public function deleteItems(array $keys): bool
    {
        return $this->adapter->deleteItems($keys);
    }
    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter->save($item);
    }
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter->saveDeferred($item);
    }
    public function commit(): bool
    {
        return $this->adapter->commit();
    }

    /* ---------------------------------------------------------------------
     *  Convenience helpers (used by tests & callers)
     * -------------------------------------------------------------------*/
    public function get(string $key): mixed
    {
        return method_exists($this->adapter, 'get')
            ? $this->adapter->get($key)
            : $this->getItem($key)->get();
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (method_exists($this->adapter, 'set')) {
            return $this->adapter->set($key, $value, $ttl);
        }
        $item = $this->getItem($key)->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }

    /**
     * Forward the runtime namespace+directory switch to adapters that support it.
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $dir = null): void
    {
        if (method_exists($this->adapter, 'setNamespaceAndDirectory')) {
            $this->adapter->setNamespaceAndDirectory($namespace, $dir);
            return;
        }
        throw new BadMethodCallException(
            sprintf('%s does not support setNamespaceAndDirectory()', $this->adapter::class)
        );
    }

    /* ---------------------------------------------------------------------
     *  ArrayAccess
     * -------------------------------------------------------------------*/
    public function offsetExists(mixed $offset): bool
    {
        return $this->hasItem((string)$offset);
    }
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string)$offset, $value);
    }
    public function offsetUnset(mixed $offset): void
    {
        $this->deleteItem((string)$offset);
    }

    /* ---------------------------------------------------------------------
     *  Magic property helpers
     * -------------------------------------------------------------------*/
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }
    public function __isset(string $name): bool
    {
        return $this->hasItem($name);
    }
    public function __unset(string $name): void
    {
        $this->deleteItem($name);
    }

    /* ---------------------------------------------------------------------
     *  Countable
     * -------------------------------------------------------------------*/
    public function count(): int
    {
        return $this->adapter instanceof Countable
            ? count($this->adapter)
            : iterator_count($this->adapter->getItems());
    }

    /* ---------------------------------------------------------------------
     *  Iterator  (exposes cached VALUES, not CacheItem objects)
     * -------------------------------------------------------------------*/
    private ?Iterator $it = null;

    public function rewind(): void
    {
        $this->it = $this->adapter instanceof Iterator
            ? $this->adapter
            : new ArrayIterator(iterator_to_array($this->getItems(), true));

        $this->it->rewind();
    }

    public function current(): mixed
    {
        return $this->it->current();
    }
    public function key(): mixed
    {
        return $this->it->key();
    }
    public function next(): void
    {
        $this->it->next();
    }
    public function valid(): bool
    {
        return $this->it->valid();
    }
}
