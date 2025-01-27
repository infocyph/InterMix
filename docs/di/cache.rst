.. _di.cache:

==================
Definition Caching
==================

InterMix can **cache** definitions so repeated calls don't re-reflect or re-resolve them,
**improving performance** across repeated usage.

It uses `Symfony Cache <https://symfony.com/doc/current/components/cache.html>`__
(``CacheInterface``). You can pass any Symfony cache adapter (e.g., FilesystemAdapter, RedisAdapter).

---------------------------------------
enableDefinitionCache(CacheInterface $cache)
---------------------------------------

Enables definition caching:

.. code-block:: php

   use Symfony\Component\Cache\Adapter\FilesystemAdapter;

   $cache = new FilesystemAdapter();
   $container->definitions()
       ->enableDefinitionCache($cache);

**After this**, any definitions the container resolves are stored in that cache.
If you remove or change a definition, or want to re-build them, see below.

-------------------------------------------
cacheAllDefinitions(bool $forceClearFirst)
-------------------------------------------

Preemptively resolve **all** container definitions, storing them in cache:

.. code-block:: php

   $container->definitions()
       ->cacheAllDefinitions($forceClearFirst = false);

- ``$forceClearFirst = true``: Clears the container’s keys from the cache first, ensuring
  a fresh build.

**Flow**:

1. For each definition (in ``functionReference``), the container calls ``resolveByDefinition($id)``.
2. The resulting object/value is cached in Symfony cache **and** in local
   :php:meth:`resolvedDefinition`.

Hence next time you call:

.. code-block:: php

   $container->get('someDefinition');

the container loads it instantly from cache. This can **significantly** reduce overhead in
production if you have complex definitions or reflection usage.

------------------
Lazy vs. Immediate
------------------

InterMix also supports **lazy loading** for definitions, which can be combined with caching.
If lazy loading is on (``enableLazyLoading(true)``) but you also want a definition to skip lazy
and be resolved eagerly, you can:

- **Bind a user closure** directly. (User closures are always resolved **immediately** for
  clarity—thus caching the result if caching is on.)
- Or call ``cacheAllDefinitions()`` so everything is forced into cache right away.

**Either way**, the container ensures you do not re-resolve the same definition repeatedly,
thanks to caching.
