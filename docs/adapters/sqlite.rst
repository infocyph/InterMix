.. _cache.adapters.sqlite:

====================
SQLite Adapter
====================

The **SqliteCacheAdapter** provides a single-file, serverless cache backed by SQLite.
Ideal for:

* **CLI tools** or single-host PHP processes
* **Small apps** needing persistence without a full Redis/Memcached setup

Schema
------

.. code-block:: sql

   CREATE TABLE IF NOT EXISTS cache (
       key      TEXT PRIMARY KEY,
       value    BLOB NOT NULL,
       expires  INTEGER
   );
   CREATE INDEX IF NOT EXISTS exp_idx ON cache(expires);

* The `expires` column is a UNIX timestamp (seconds). If `expires IS NULL`, the item never expires.

Bulk Fetch (`multiFetch`)
-------------------------

Instead of N separate `SELECT` calls, `getItems()` does:

1. `SELECT key, value, expires FROM cache WHERE key IN (?, ?, …)`
2. Build an associative map of returned rows by `key`
3. For each requested key:
   - If found **and** not expired: `ValueSerializer::unserialize(value)` → return `SqliteCacheItem` (hit)
   - If expired: `DELETE FROM cache WHERE key = ?` → return new `SqliteCacheItem` (miss)
   - If not found: return new `SqliteCacheItem` (miss)

Example:

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   $file = sys_get_temp_dir() . '/cache_foo.sqlite';
   $pool = Cache::sqlite('foo', $file);

   // Lazy set/get
   $val = $pool->get('chart', function ($item) {
       $item->expiresAfter(300);
       return renderChart();
   });

   // Bulk fetch
   $items = $pool->getItems(['chart','report','stats']);
   foreach ($items as $k => $item) {
       if ($item->isHit()) {
           $data = $item->get();
       }
   }

Clearing All Entries
--------------------

`clear()` simply runs `DELETE FROM cache` on the table and resets the deferred queue.

Performance Notes
-----------------

* A single SQLite file can handle thousands of keys, but write throughput is limited by
  SQLite’s journaling overhead.
* Consider using `memory:` or `file::memory:?cache=shared` DSNs for in-memory databases in tests.
