.. _cache.adapters.redis:

==================
Redis Adapter
==================

Backed by `ext-redis` and supports clusters or UNIX sockets.

Quick setup
-----------

.. code-block:: php

   $r = new Redis();
   $r->connect('127.0.0.1', 6379);
   $pool = Cachepool::redis('site', $r);

Highlights
----------

* **multiFetch()** via ``MGET`` (one round-trip).
* Optional Redis 6 ACL user/pass handled by you on the connection.
* TTL uses native ``EXPIRE`` seconds.
* Atomic ``SET`` with ``PX`` when you call ``expiresAfter()``.

Persistence
-----------

Depends on your Redis config (AOF, RDB,  replication).
Large binary payloads are encoded by
:doc:`../serialization` then saved as plain strings.
