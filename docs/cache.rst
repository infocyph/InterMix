.. _cache:

===========================
Cache – Unified Facade
===========================

The **``Cache``** class is a PSR-6 and PSR-16–compliant cache façade with an adapter-agnostic interface.
It provides:

* **Static factories** for common back-ends:
  - ``Cache::file()``
  - ``Cache::apcu()``
  - ``Cache::memcache()``
  - ``Cache::redis()``
  - ``Cache::sqlite()``
* A **convenience API** layering on top of PSR-6 and PSR-16:
  - **PSR-6 extras**:
    - ``set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool``
    - ``get(string $key, mixed $default = null): mixed``
      - If ``$default`` is a callable, it will be invoked on a cache miss with the ``CacheItemInterface`` as argument, the returned value will be saved (with any TTL set inside the callback) and then returned.
  - **Magic properties** (``$cache->foo``, ``$cache->foo = 'bar'``)
  - **ArrayAccess** (``$cache['id']``)
  - **Countable** (``count($cache)``)
* **Bulk fetch** (``getItems([...])``) that defers to adapter for single-round-trip performance
* **Iteration** (``foreach ($cache->getItemsIterator() as $k => $v)``)
* Automatic **serialization** of closures, resources and arbitrary PHP values

-------------------------------
Quick Start
-------------------------------

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   // 1) File-based cache in “docs” namespace (under /tmp by default)
   $cache = Cache::file('docs');

   // 2) Simple PSR-16 set()/get()
   $cache->set('answer', 42, 3600);
   echo $cache->get('answer');    // 42

   // 3) Lazy compute on miss (PSR-16 style):
   $userProfile = $cache->get('user_123', function ($item) {
       // $item is a PSR-6 CacheItemInterface: set a TTL here
       $item->expiresAfter(600);
       return fetchProfileFromDatabase(123);
   });

   // 4) Bulk fetch multiple keys in a single round-trip:
   $items = $cache->getItems(['foo', 'bar', 'baz']);
   // $items['foo']->isHit() ? $items['foo']->get() : null

-------------------------------
Feature Matrix
-------------------------------

.. csv-table::
   :widths: 20, 10, 10, 10, 10, 10
   :header: “Adapter”, “multi-get”, “TTL”, “Tags†”, “Atomic?”, “Persistence”

   FileCache,      ✓,            ✓,    ✓,     “LOCK_EX” (host), “temp-files”
   APCu,           ✓,            ✓,    ✓,     “apcu_cas” best effort, “RAM”
   Memcached,      ✓,            ✓,    ✓,     “true” (server), “network-RAM”
   Redis,          ✓,            ✓,    ✓,     “true” (server), “network-RAM”
   SQLite,         ✓,            ✓,    ✓,     “true” (SQL lock), “.sqlite file”

-------------------------------
Public API
-------------------------------

PSR-6 Methods (delegated to the underlying adapter):

* **``getItem(string $key): CacheItemInterface``**
* **``getItems(array $keys = []): iterable``**
  (internally calls adapter’s ``multiFetch()`` if available)
* **``hasItem(string $key): bool``**
* **``clear(): bool``**
* **``deleteItem(string $key): bool``**
* **``deleteItems(array $keys): bool``**
* **``save(CacheItemInterface $item): bool``**
* **``saveDeferred(CacheItemInterface $item): bool``**
* **``commit(): bool``**

PSR-16 Methods (implemented on top of PSR-6):

* **``get(string $key, mixed $default = null): mixed``**
  - Returns the cached value or ``$default``.
  - If ``$default`` is a **callable**, then on a cache miss the callable is invoked with the ``CacheItemInterface`` argument; its return value is saved (respecting any TTL set inside the callback) and then returned.
* **``set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool``**
  - Shortcut for “create a new CacheItem → set($value) → expiresAfter($ttl) → save()”.
* **``remember(string $key, callable $resolver, int|DateInterval|null $ttl = null, array $tags = []): mixed``**
  - Stampede-protected compute-on-miss with host-local lock + jittered TTL.
* **``setTagged(string $key, mixed $value, array $tags, int|DateInterval|null $ttl = null): bool``**
  - Store a value and associate it with one or more tags.
* **``invalidateTag(string $tag): bool``**
  - Delete all keys associated with a tag.
* **``invalidateTags(array $tags): bool``**
  - Batch invalidation for multiple tags.
* **``has(string $key): bool``**
  - Equivalent to ``hasItem(string $key)``.
* **``delete(string $key): bool``**
  - Equivalent to ``deleteItem(string $key)``.
* **``clear(): bool``**
  - Equivalent to ``clear()``.
* **``getMultiple(iterable $keys, mixed $default = null): iterable``**
  - Fetches multiple keys.
  - If a ``$default`` is provided (scalar or callable), it applies per-key on miss.
* **``setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool``**
  - Accepts an associative array or Traversable of ``$key => $value``, sets each with optional TTL.
* **``deleteMultiple(iterable $keys): bool``**
  - Alias for ``deleteItems(array $keys)``.
* **Cache Stampede Protection**:
  - ``remember()`` method provides compute-once with host-local file locking
  - Automatic jittered TTL to prevent thundering herd effects
  - Lock timeout and retry mechanisms for high concurrency scenarios

Magic props & ArrayAccess & Countable:

* **Magic props:**
 - ``$cache->foo``  ↔  ``$cache->get('foo')``
 - ``$cache->foo = 'bar'``  ↔ ``$cache->set('foo','bar')``
 - ``isset($cache->foo)``  ↔  ``$cache->hasItem('foo')``
 - ``unset($cache->foo)``  ↔  ``$cache->deleteItem('foo')``
* **ArrayAccess:**
 - ``$cache['id'] = 123`` ; ``$val = $cache['id']`` ; ``isset($cache['id'])`` ; ``unset($cache['id'])``
* **Countable:**
 - ``count($cache)`` delegates to either adapter’s ``count()`` or does a manual scan if the adapter is not ``Countable``.

Optional:

- ``setNamespaceAndDirectory(string $namespace, string|null $dir)``
- Only supported if the adapter implements it (FileCacheAdapter is the primary one).
- Allows changing namespace and/or storage location at runtime.

.. toctree::
    :titlesonly:
    :hidden:

    adapters/serialization
    adapters/file
    adapters/apcu
    adapters/memcached
    adapters/redis
    adapters/sqlite
