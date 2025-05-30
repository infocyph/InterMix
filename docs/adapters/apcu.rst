.. _cache.adapters.apcu:

=====================
APCu Adapter
=====================

In-process RAM cache. Perfect for CLI scripts and single-server web apps.

Requirements
------------

* ``ext-apcu`` **and** ``apc.enable_cli=1`` for CLI testing

Key points
----------

* **Namespace prefix**: ``${ns}:key``.
* **Multi-get** uses one ``apcu_fetch([])`` call (O(1)).
* TTL honoured by APCu itself; expired keys are purged lazily.

.. code-block:: php

   $pool = Cachepool::apcu('docs');
   $pool->set('stat', computeStats(), 120);
