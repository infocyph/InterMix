.. _cache.adapters.file:

=====================
FileCache Adapter
=====================

The **FileCacheAdapter** stores one file **per key** under a namespaced directory.
It is:

* **Zero-dependencies** (no external PHP extensions needed)
* **Debug-friendly** (you can open the .cache files and inspect)
* **Portable** (runs on any local filesystem)

…but also:

* **Not suitable** for NFS or network shares (file‐locking issues)
* **Creates many small files** if you have thousands of keys
* **Potentially slower** on spinning disks when directories grow large

Directory Layout
----------------

By default:

* Base directory: `sys_get_temp_dir()` (e.g. `/tmp` on Linux)
* Per-namespace subdirectory: `cache_<namespace>`
* Each key → `hash('xxh128', $key) . '.cache'`

Example:

.. code-block:: text

   /tmp/cache_thumbs/
     ├── 3a9f2d1e157e0a1d0a53e4b2b7a3bfd.cache
     ├── a12c4491b2deef4b560d2fdd43a3bdf7.cache
     └── …

Concurrency & Locks
-------------------

* When you call `save()`, the adapter uses `file_put_contents(..., LOCK_EX)`
  to avoid partial writes.
* Reading does **not** use locks (there is a small race-condition if a write is in progress).
* Bulk `getItems()` scans the directory once, checks each file’s content, and unserializes hits.

Hot-Reload / Namespace Change
-----------------------------

You can call:

.. code-block:: php

   $cache->setNamespaceAndDirectory('newns', '/path/to/custom-dir');

This will:

1. Create (if needed) `/path/to/custom-dir/cache_newns/`
2. Discard any existing deferred queue or iterator snapshot
3. Subsequent calls use the new directory/namespace

Example Usage
-------------

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;

   // Create a file-based cache “thumbs” under /var/tmp
   $pool = Cache::file('thumbs', '/var/tmp');

   // Lazy compute a thumbnail on cache miss:
   $thumb1 = $pool->get('page_1', function ($item) {
       $item->expiresAfter(300);
       return generateThumbnail(1);
   });

   // Bulk fetch three thumbnail entries
   $items = $pool->getItems(['page_1','page_2','page_3']);
   foreach ($items as $page => $item) {
       if ($item->isHit()) {
           // Use $item->get()
       } else {
           // Compute or ignore
       }
   }
