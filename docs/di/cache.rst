.. _di.cache:

========================
Definition-level Caching
========================

InterMix can **memoise resolved definitions** in a styled cache so that
the next request (or CLI run) skips *all* reflection, attribute parsing and
factory execution.

Why care?

* **Speed** – complex graphs become a quick cache lookup.
* **Memory** – expensive reflection metadata lives in PSR-6 / PSR-16 storage.
* **Predictability** – warm the cache once, ship to production.

---------------
Quick Example 🚀
---------------

.. code-block:: php

   use Infocyph\InterMix\Cache\Cache;
   use function Infocyph\InterMix\container;

   $c = container();

   // ① choose any PSR-6 adapter
   $cache = Cache::file(namespace: 'intermix', directory: '/tmp/intermix');

   // ② plug it into the DefinitionManager
   $c->definitions()->enableDefinitionCache($cache);

   // ③ bind / register as usual …
   $c->definitions()->bind('answer', 42);

   // ④ first call populates the cache
   $val = $c->get('answer');          // ← resolves & stores

   // ⑤ subsequent calls – *even in a new PHP process* – are instant
   echo $c->get('answer');            // ← pulled straight from cache


Eager warm-up (a.k.a. *compile* the container):

.. code-block:: php

   $c->definitions()->cacheAllDefinitions(forceClearFirst: true);

`cacheAllDefinitions()` iterates every current definition **once**,
resolves it and stores the result in:

1. **The cache** you supplied, *and*
2. The container’s in-process “resolved” map (so it is also fast in memory).

You can now deploy the warmed cache files or keep them in Redis, Memcached,
APCu, etc.

--------------------------------
Lazy Loading × Caching ⚡️
--------------------------------

Lazy loading (``enableLazyLoading(true)``) and caching work **together**:

* **Class / array bindings**
  → stored as a lightweight ``DeferredInitializer``
  → first ``get()`` constructs the object **and** writes it to cache.

* **User-supplied closures**
  → executed **immediately** (never deferred)
  → whatever the closure returns is cached.

That means you may keep lazy loading **on** and still enjoy the *speed* of a
pre-warmed cache.

----------------------
Cache Invalidation 🔄
----------------------

* **forceClearFirst** in `cacheAllDefinitions()` nukes InterMix keys **before**
  warming.
* Delete the cache namespace directory (FilesystemAdapter) or flush the key
  pattern (Redis/Memcached) whenever you update service wiring.

--------------------------------
FAQ ❓
--------------------------------

**Q: Do I need caching in development?**
Not really – autowiring overhead is negligible for most apps.  Use it for
benchmarks or when profiling reveals reflection hot-spots.

**Q: Can I share the cache between multiple container aliases?**
Yes, each alias prefixes its keys, so collisions are impossible.

**Q: Does it store _all_ objects?**
Only what you resolve **and** what is not marked *Transient*. Transient lifetimes
are always rebuilt (by design), so caching would defeat their purpose.

---------------------
One-liner Cheat-Sheet
---------------------

===========  =================================================================
Action        Code
===========  =================================================================
Enable cache  ``$c->definitions()->enableDefinitionCache($cache)``
Warm all      ``$c->definitions()->cacheAllDefinitions()``
Clear + warm  ``…->cacheAllDefinitions(forceClearFirst:true)``
Disable       Omit the call or inject the `NullAdapter`
===========  =================================================================

Next stop » :doc:`debug_tracing`
