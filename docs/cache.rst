.. _cache:

===========================
Cache – Unified Facade
===========================

The **`Infocyph\InterMix\Cache\Cache`** class is a PSR-6–compliant cache‐item pool
with a consistent, adapter‐agnostic façade. It provides:

* **Static factories** for common back‐ends:
  - `Cache::file()`
  - `Cache::apcu()`
  - `Cache::memcache()`
  - `Cache::redis()`
  - `Cache::sqlite()`
* A **convenience API** that layers on top of PSR-6:
  - `set($key, $value, $ttl)`
  - `get($key, $lazyCallback = null)` with lazy-compute on miss
  - magic properties (`$cache->foo`, `$cache->foo = 'bar'`)
  - `ArrayAccess` (`$cache['id']`)
* **Bulk fetch** (`getItems([...])`) that defers to adapter‐specific `multiFetch()`
  for single‐round-trip performance
* **Iteration + Countable** (so you can `foreach ($cache as $k => $v)` or `count($cache)`)
* Automatic **serialization** (via `Infocyph\InterMix\Serializer\ValueSerializer`) to
  support closures, resources, and arbitrary PHP values

.. tip::

   This façade is strictly PSR-6. If you need PSR-16 (Simple Cache) or Symfony Cache,
   wrap**-and-bridge** externally.

Quick Start
===========

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   // 1) File-based cache in “docs” namespace, under /tmp (or system temp by default)
   $cache = Cache::file('docs');

   // 2) Simple set/get
   $cache->set('answer', 42, ttl: 3600);
   echo $cache->get('answer');    // 42

   // 3) Lazy compute when missing (Symfony style):
   $userProfile = $cache->get('user_123', function ($item) {
       // $item is a PSR-6 CacheItemInterface; you can set TTL here:
       $item->expiresAfter(600);
       return fetchProfileFromDatabase(123);
   });

   // 4) Bulk fetch multiple keys in a single round-trip:
   $items = $cache->getItems(['foo', 'bar', 'baz']);
   // $items['foo']->isHit() ? $items['foo']->get() : null

Feature Matrix
==============

.. csv-table::
   :widths: 20, 10, 10, 10, 10, 10
   :header: “Adapter”, “multi‐get”, “TTL”, “Tags†”, “Atomic?”, “Persistence”

   FileCache,      ✓,            ✓,    –,     “LOCK_EX” (host), “temp‐files”
   APCu,           ✓,            ✓,    –,     “apcu_cas” best effort, “RAM”
   Memcached,      ✓,            ✓,    –,     “true” (server), “network‐RAM”
   Redis,          ✓,            ✓,    –,     “true” (server), “network‐RAM”
   SQLite,         ✓,            ✓,    –,     “true” (SQL lock), “.sqlite file”

† Tag support (cache invalidations by tag) is planned for a future version.

Public API
==========

PSR-6 Methods (delegated to the underlying adapter):

* **`getItem(string $key): CacheItemInterface`**
* **`getItems(array $keys = []): iterable`**
  (under the hood, calls `multiFetch()` on adapters that support it)
* **`hasItem(string $key): bool`**
* **`clear(): bool`**
* **`deleteItem(string $key): bool`**
* **`deleteItems(array $keys): bool`**
* **`save(CacheItemInterface $item): bool`**
* **`saveDeferred(CacheItemInterface $item): bool`**
* **`commit(): bool`**

Extras on the façade:

.. list-table::
   :widths: 15, 75
   :header-rows: 0

   * - `set(string $key, mixed $value, int|null $ttl = null): bool`
     - Shortcut for “create a new CacheItem → set($value) → expiresAfter($ttl) → save()”.
   * - `get(string $key, callable|null $callback = null): mixed`
     - Fetch a value directly; if `$callback` is provided and the key is missing,
       invoke `$callback(CacheItemInterface $item)`, save its return, and return it.
   * - Magic props:
     - `$cache->foo`  ↔  `$cache->get('foo')`
     - `$cache->foo = 'bar'`  ↔ `$cache->set('foo','bar')`
     - `isset($cache->foo)`  ↔  `$cache->hasItem('foo')`
     - `unset($cache->foo)`  ↔  `$cache->deleteItem('foo')`
   * - `ArrayAccess`:
     - `$cache['id'] = 123` ; `$val = $cache['id']` ; `isset($cache['id'])` ; `unset($cache['id'])`
   * - `Countable`:
     - `count($cache)` delegates to either adapter’s `count()`, or does a manual scan if not `Countable`.
   * - **Optional** `setNamespaceAndDirectory(string $namespace, string|null $dir)`
     - Only valid if the adapter implements this (FileCacheAdapter is the primary one).

Why use “getItems”?
-------------------

Most adapters implement a batched “multiFetch()” call that can fetch **N** keys
in **1** round-trip—rather than doing **N** separate `getItem()` calls. This is
significant when talking to remote stores (Redis, Memcached, SQLite). The façade
automatically checks `method_exists($adapter, 'multiFetch')` and uses it.

See each adapter’s page for implementation details and performance notes.

—–
.. toctree::
   :maxdepth: 1
   :caption: Back‐end Adapters

   adapters/file
   adapters/apcu
   adapters/memcached
   adapters/redis
   adapters/sqlite

.. toctree::
   :maxdepth: 1
   :caption: Internals

   serialization
