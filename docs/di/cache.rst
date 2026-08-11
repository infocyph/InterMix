.. _di.cache:

========================
Definition-level Caching
========================

InterMix can persist safe singleton definition results in any PSR-6 pool. The
cache is an optional optimization: null, scalar, and recursively safe array
values may be persisted; objects, Closures, and resources remain runtime-only.
Scoped and transient definitions are never stored externally.

CacheLayer 3.1 is the recommended Infocyph implementation and is continuously
integration-tested with InterMix, but it is not required at runtime. Symfony
Cache, framework pools, custom pools, and every other conforming PSR-6
implementation remain supported.

--------------------
Basic PSR-6 usage
--------------------

.. code-block:: php

   use Psr\Cache\CacheItemPoolInterface;

   /** @var CacheItemPoolInterface $pool */
   $pool = getYourPsr6Pool();

   $c->definitions()->enableDefinitionCache(
       $pool,
       generation: 'release-2026-08-11',
   );
   $c->definitions()->bind('answer', static fn (): int => 42);

   $answer = $c->get('answer');

The first container resolves and stores ``answer``. A separate container with
the same alias, environment, definition identity, and generation can reuse it.
Within one container, resolved singleton state wins before PSR-6 is consulted.

Cache failures fail open by default: a read failure becomes a miss and a write
failure does not prevent the resolved value being returned. Strict deployments
can surface backend failures:

.. code-block:: php

   $c->definitions()->enableDefinitionCache(
       $pool,
       generation: $releaseId,
       failOpen: false,
   );

InterMix does not retry, lock, log, or trace cache failures automatically.

--------------------------
Recommended CacheLayer 3.1
--------------------------

Memory is deterministic and useful in tests:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memory('intermix.definitions');
   $c->definitions()->enableDefinitionCache($cache);

APCu is the lowest-latency production choice for PHP-FPM when the extension is
available:

.. code-block:: php

   $cache = Cache::apcu('intermix.definitions');
   $c->definitions()->enableDefinitionCache($cache, generation: $releaseId);

SQLite/PDO provides persistent local storage for CLI, workers, and single-node
services:

.. code-block:: php

   $cache = Cache::sqlite(
       'intermix.definitions',
       '/var/cache/application/intermix.sqlite',
   );

For an APCu L1 backed by local SQLite, opt in to Node Cache:

.. code-block:: php

   use Infocyph\CacheLayer\Node\NodeCache;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   $cache = NodeCache::create(new NodeCacheConfig(
       namespace: 'intermix.definitions',
       sqliteFile: '/var/cache/application/intermix.sqlite',
   ));

Tiered pools are also ordinary PSR-6 pools:

.. code-block:: php

   $cache = Cache::tiered([
       ['driver' => 'apcu', 'namespace' => 'intermix.definitions'],
       [
           'driver' => 'sqlite',
           'namespace' => 'intermix.definitions',
           'file' => '/var/cache/application/intermix.sqlite',
       ],
   ]);

Redis, Valkey, and Memcached are supported but are situational: network latency
can exceed the local work saved for small definition values. InterMix does not
configure CacheLayer tags, locking, metrics, compression, serialization, or
cluster features. Those remain application-owned CacheLayer concerns.

--------------------
Bulk warmup
--------------------

.. code-block:: php

   $report = $c->definitions()->warmDefinitionCache(
       rotateGeneration: true,
   );

   // ['hits' => 20, 'written' => 5, 'skipped' => 8, 'failed' => 0]

Warmup collects singleton keys, performs one ``getItems()`` call, resolves only
misses, uses ``saveDeferred()``, and calls ``commit()`` once. ``skipped`` counts
scoped/transient definitions and resolved values that InterMix intentionally
does not persist. ``failed`` counts cache work that failed open. Rotation never
calls ``clear()`` on the caller-owned pool, so unrelated entries survive.

-------------------------
Namespace and generation
-------------------------

A CacheLayer namespace such as ``my-app.intermix`` identifies application/cache
ownership. The InterMix generation identifies the release or DI configuration.
Keep both: definition, environment, alias, and generation coordinates are
hashed into short keys shaped like ``imx.<hash>.<hash>.<hash>``. Raw service
IDs, class names, environments, tenant names, and paths are not exposed.

Configuration mutations rotate InterMix's logical generation in O(1); old
entries become unreachable and the shared pool is never cleared. Re-enabling
the same pool with the same generation and failure policy is idempotent.

-----------------------------
Compiled resolver or cache?
-----------------------------

They optimize different work. Compiled resolvers reduce reflection and object
construction recipe overhead. Definition caching reuses safe scalar/array
singleton results across containers. Production priority is generally:

#. compiled resolver with OPcache or preload;
#. in-memory singleton/scoped reuse;
#. optional PSR-6 definition caching.

For object-heavy applications, compiled resolution is normally the larger win.

Next stop » :doc:`debug_tracing`
