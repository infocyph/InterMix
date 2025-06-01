.. _cache.adapters.redis:

==================
Redis Adapter
==================

The **RedisCacheAdapter** uses the `Redis` (phpredis) extension. It supports:

* **Single or clustered Redis** (including Sentinel or Cluster modes)
* **ACL/username/password** via DSN (e.g. `redis://:pass@host:6379/0`)
* **UNIX socket** connections (`redis:///path/to/socket`)

Highlights
----------

* **multiFetch()** via `MGET` → one round-trip for any number of keys
* **Native TTL** → uses `SETEX` or `EXPIRE`
* **Atomic** operations guaranteed by Redis
* **Persistence** depends on your Redis configuration (RDB/AOF)
* Values are stored as plain strings (the result of `ValueSerializer::serialize($item)`)

Quick Setup
-----------

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   // (a) Let adapter parse a DSN and create the client:
   $pool = Cache::redis('site', 'redis://127.0.0.1:6379');

   // (b) Pass an existing Redis instance you’ve already authenticated:
   $r = new Redis();
   $r->connect('127.0.0.1', 6379);
   $r->auth('myPassword');
   $r->select(2);
   $pool2 = Cache::redis('site', '', $r);

Saving with TTL
---------------

Internally, on `save(CacheItemInterface $item)`, the adapter does:

.. code-block:: php

   $blob = ValueSerializer::serialize($item);
   $ttl = $item->ttlSeconds();
   if ($ttl !== null) {
       $this->redis->setex($ns . ':' . $key, $ttl, $blob);
   } else {
       $this->redis->set($ns . ':' . $key, $blob);
   }

Bulk Fetch Example
------------------

.. code-block:: php

   $keys = ['r1','r2','r3'];
   $items = $pool->getItems($keys);
   // Internally runs: $raws = $redis->mget(['ns:r1','ns:r2','ns:r3']);
   // Then unserializes each blob, wraps into RedisCacheItem.

Clearing a Namespace
--------------------

`clear(): bool` uses `SCAN` to iterate over all keys in the `“ns:*”` pattern and deletes them in batches,
avoiding large blocking operations.

Example:

.. code-block:: php

   // Clear all keys under “site:” prefix
   $pool->clear();
