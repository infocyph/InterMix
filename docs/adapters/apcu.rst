.. _cache.adapters.apcu:

=====================
APCu Adapter
=====================

The **ApcuCacheAdapter** leverages PHP’s in-process APCu extension. Ideal for:

* **Single‐server web applications** (shared memory across FPM workers)
* **CLI scripts** (if `apc.enable_cli=1`)

Key Points
----------

* **Namespacing**: every key is prefixed as `<namespace>:<key>`.
* **multiFetch()** uses one `apcu_fetch(array $keys)` call (O(1) in number of round trips).
* **TTL** is handled natively by APCu. Expired keys are purged lazily by PHP.
* **Atomic-save** is best attemped by `apcu_cas`, but in practice, saving an entire serialized
  blob is usually atomic enough in a single call (APCu is mutex‐protected internally).

Requirements
------------

* `ext-apcu` must be installed and enabled
* CLI usage requires `apc.enable_cli=1` (otherwise, FPM/web might work, but
  your CLI tests will be skipped)

Example

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;
   $pool = Cache::apcu('stats');

   // Store a value for 120 seconds
   $pool->set('dashboard_count', $count, 120);

   // Retrieve or null if missing
   $n = $pool->get('dashboard_count');

Bulk fetch:

.. code-block:: php

   $results = $pool->getItems(['a','b','c']);
   // yields ApcuCacheItem instances; .isHit() tells you if it was present.

Note on `ValueSerializer`
-------------------------

APCu stores only strings; we wrap/unserialize via:

1. `ValueSerializer::serialize($item)` → store as blob
2. On fetch, `ValueSerializer::unserialize($blob)` → reconstruct a `CacheItem` object

In other words, each stored APCu value is a serialized `ApcuCacheItem` array.

