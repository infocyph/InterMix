.. _adapters.file:

=====================
FileCache Adapter
=====================

* **Location:** system temp-dir by default (``/tmp`` on *nix).
* **Layout:** ``${base}/cache_${namespace}/xxh128(key).cache``
* **Concurrency:** uses ``LOCK_EX`` when writing to avoid corruption.
* **Multi-get:** scans file paths in one pass, deserialises only hits.
* **Hot reload:** call
  ``$pool->setNamespaceAndDirectory('newns', '/path/cache')`` at runtime.

Pros / cons
===========

+ **Zero deps**, portable, debug-friendly (read the files).
– Not suitable for NFS or network shares.
– One file per key ⇒ many small inodes.

Performance tips
----------------

1. Put the cache directory on a tmpfs/ramdisk if you need speed.
2. Use namespaces to avoid hashing thousands of files in a single folder.

Example
-------

.. code-block:: php

   $pool = Cachepool::file('thumbs', '/var/tmp');
   $img  = $pool->get('page_1', function ($i) {
       $i->expiresAfter(300);
       return generateThumbnail(1);
   });
