.. _cache.adapters.sqlite:

====================
SQLite Adapter
====================

Single-file, serverless cache useful for CLI tools or small
apps that need persistence but not a full Redis/Memcached setup.

Schema
------

.. code-block:: sql

   CREATE TABLE IF NOT EXISTS cache (
       key     TEXT PRIMARY KEY,
       value   BLOB NOT NULL,
       expires INTEGER
   );

Bulk fetch
----------

``multiFetch()`` issues one ``SELECT … IN (…)`` and deletes rows whose
``expires`` ≤ ``time()`` on the fly.

Example
-------

.. code-block:: php

   $file = sys_get_temp_dir().'/my.db';
   $pool = Cache::sqlite('tmp', $file);

   $data = $pool->get('chart', function ($i) {
       $i->expiresAfter(300);
       return renderChart();
   });
