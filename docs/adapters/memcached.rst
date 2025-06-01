.. _cache.adapters.memcached:

========================
Memcached Adapter
========================

The **MemCacheAdapter** connects to one or more Memcached servers via the `\Memcached` extension.
Suitable for:

* **Distributed caching** across multiple web servers
* **Shared high-speed RAM cache** among many PHP workers
* **Large data sets** (within memory/slab constraints)

Connection
----------

You must supply either:

1. A pre-configured `\Memcached` instance
2. A list of server triples (`[host, port, weight]`)

Example:

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   // (a) Pass your own Memcached client:
   $mc = new Memcached();
   $mc->addServer('127.0.0.1', 11211);
   $pool = Cache::memcache('site', [], $mc);

   // (b) Let adapter build one for you:
   $pool2 = Cache::memcache('site', [['127.0.0.1',11211,0]]);

Key Length Limit
----------------

Memcached keys must be ≤ 250 bytes. The adapter does **not** automatically hash or trim;
ensure your namespace + key fit within this limit.

multiFetch()
------------

Internally, `getItems()` calls `multiFetch(array $keys)`, which:

1. Maps each key to `<ns>:<key>`
2. Calls `$memcached->getMulti([...])`
3. Returns an array of `MemCacheItem` instances, preserving order

TTL & Eviction
--------------

* TTL is stored per-item via `$memcached->set($ns . ':' . $key, $blob, $ttl)`.
* Memcached’s LRU + slab allocator evicts entries when RAM is full.
* No persistence across server restarts.

Example Usage
-------------

.. code-block:: php

   // Create a Memcached-backed pool in “site” namespace
   $pool = Cache::memcache('site');

   // Basic set/get
   $pool->set('alpha', 'foo', 120);
   echo $pool->get('alpha');  // "foo"

   // Bulk fetch
   $items = $pool->getItems(['alpha','beta','gamma']);
   foreach ($items as $k => $item) {
       if ($item->isHit()) {
           echo "$k => " . $item->get();
       }
   }

   // Clear entire pool
   $pool->clear();  // flushes all keys in this namespace (server-side)
