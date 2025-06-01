.. _cache.adapters.memcached:

========================
Memcached Adapter
========================

Networked, distributed, LRU-evicting RAM cache.

Connection
----------

.. code-block:: php

   $mc = new Memcached();
   $mc->addServer('127.0.0.1', 11211);
   $pool = Cache::memcached('site', $mc);

Features
--------

* **multiFetch()** via ``getMulti()``
* Binary protocol supported (pass configured ``Memcached`` instance).
* Key length limited to **250 bytes** by memcached server.
* No persistence across restarts.

TTL & eviction
--------------

* TTL stored per‐item.
* LRU + Memcached’s slab allocator decides final eviction.
