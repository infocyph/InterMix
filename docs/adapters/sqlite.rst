.. _cache.adapters.sqlite:

====================
SQLite Adapter
====================

The **SqliteCacheAdapter** offers a single‐file, serverless cache backed by SQLite.
It is:

* **Ideal for**: CLI tools, single‐host PHP processes, small apps where you want
  persistence to disk without the overhead of Redis/Memcached.
* **Schema**: one table in `_cache_<namespace>.sqlite_`:

  .. code-block:: sql

     CREATE TABLE IF NOT EXISTS cache (
         key      TEXT PRIMARY KEY,
         value    BLOB    NOT NULL,
         expires  INTEGER
     );

  and an index on `expires`:

  .. code-block:: sql

     CREATE INDEX IF NOT EXISTS exp_idx ON cache(expires);

  The `expires` column is a UNIX timestamp (seconds). If `expires IS NULL`, the
  item never expires.

Bulk (multiFetch)
-----------------

Instead of N individual `SELECT` calls, we do:

1. `SELECT key,value,expires FROM cache WHERE key IN (?, ?, …)`
2. Build an associative map of returned rows keyed by `key`
3. For each requested key:
   - If present **and** not expired: `ValueSerializer::unserialize(value)` → return `SqliteCacheItem` (hit)
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

`clear()` simply runs `DELETE FROM cache` on the table, and resets the deferred queue.

Performance Notes
-----------------

* A single SQLite file can handle thousands of keys, but write throughput is limited by
  SQLite’s WAL/journal overhead.
* Use “sqlite:/path/to/file.sqlite” or “file::memory:?cache=shared” as DSN if you prefer
  an in‐memory or shared memory DB for faster tests.
