.. _cache.adapters.apcu:

=====================
APCu Adapter
=====================

The **ApcuCacheAdapter** uses PHP’s in-process APCu extension. Ideal for:

* **Single-server web applications** (shared memory across FPM workers)
* **CLI scripts** (requires `apc.enable_cli=1`)

Key Points
----------

* **Namespace prefix**: every key is stored under `<namespace>:<key>`.
* **multiFetch()** uses one `apcu_fetch(array $keys)` call (one round-trip).
* **TTL** is honored natively by APCu; expired keys are purged lazily.
* **Atomic-save** is best-effort via `apcu_cas`, but storing a serialized blob is generally atomic.

Requirements
------------

* `ext-apcu` must be installed and enabled.
* For CLI testing, `apc.enable_cli=1` must be set.

Example:

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;
   $pool = Cache::apcu('stats');

   // Store a value for 120 seconds
   $pool->set('dashboard_count', $count, 120);

   // Retrieve or null if missing
   $n = $pool->get('dashboard_count');

Bulk Fetch:

.. code-block:: php

   $results = $pool->getItems(['a','b','c']);
   // Returns ApcuCacheItem instances; .isHit() tells you if each key was present.

Note on ValueSerializer
-----------------------

APCu stores only strings. We wrap/unserialize via:

1. `ValueSerializer::serialize($item)` → store as string blob
2. On fetch, `ValueSerializer::unserialize($blob)` → reconstruct the `ApcuCacheItem` object

In other words, each APCu entry is a serialized `ApcuCacheItem` containing key, value, hit, and expiration.

