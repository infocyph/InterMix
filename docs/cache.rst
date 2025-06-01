.. _cache:

===========================
Cache – Unified Facade
===========================

`Infocyph.InterMix.Cache.Cache` is an **adapter-agnostic** PSR-6 pool
with ergonomic extras:

* **Static factories** – `Cache::file()`, `::apcu()`, `::memcached()`,
  `::redis()`, `::sqlite()`
* **Convenience API** – `set()`, `get()`, magic props, `ArrayAccess`
* **Bulk fetch** – `getItems()` delegates to adapter-specific
  ``multiFetch()`` for single round-trip performance
* **Lazy `get()`** – Symfony-style closure that computes & saves on miss
* **Iterator + Countable** – `foreach ($cache as $k => $v)` «just works»

.. tip::

   Cache is PSR-6–only by design.
   Wrap it in *Symfony Cache* or *PSR-16 bridge* if you need those APIs.

Quick start
===========

.. code-block:: php

   $cache = Cache::file('docs');

   // simple set / get
   $cache->set('answer', 42, ttl:3600);
   echo $cache->get('answer');           // 42

   // lazy compute
   $profile = $cache->get('user_1', function ($item) {
       $item->expiresAfter(600);
       return fetchProfileFromDb(1);
   });

   // bulk fetch
   $items = $cache->getItems(['a','b','c']);

Feature matrix
==============

=================  ========  =====  ======  =====  ======
Adapter             Multi-get  TTL   Tags†  Atomic  Share
=================  ========  =====  ======  =====  ======
File                ✓         ✓     –      FS-lock host
APCu                ✓         ✓     –      🟡*     RAM   *
Memcached           ✓         ✓     –      🟢      LAN
Redis               ✓         ✓     –      🟢      LAN
SQLite              ✓         ✓     –      🟢      host
=================  ========  =====  ======  =====  ======

† Tags/invalidations are future work.
🟡 APCu atomic-save is best-effort; enabled by `apcu_cas`.
🟢 Network stores are atomic via server.

Public API
==========

* **PSR-6** – `getItem()`, `getItems()`, `save()`, `saveDeferred()`,
  `commit()`, `deleteItem()`, `clear()`, etc.
* **Extras**

  ===============  ==========================================================
  `set($k,$v,$ttl)`  shortcut for (*create → set → expiresAfter → save*)
  `get($k,$cb,$ttl)` lazy-compute on miss (closure receives `CacheItem`)
  Magic props       `$cache->foo`, `$cache->foo = 1`, `unset($cache->foo)`
  ArrayAccess       `$cache['id']`
  Iteration         `foreach ($cache as $key => $value)`
  `setNamespaceAndDirectory()` only on the File adapter
  ===============  ==========================================================

.. toctree::
   :maxdepth: 1
   :caption: Back-end adapters

   adapters/file
   adapters/apcu
   adapters/memcached
   adapters/redis
   adapters/sqlite

.. toctree::
   :maxdepth: 1
   :caption: Internals

   serialization
