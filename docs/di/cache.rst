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

   use function Infocyph\InterMix\container;

   $c = container('intermix');

   // ① enable definition cache (file-backed, namespaced)
   $c->definitions()->enableDefinitionCache('intermix');

   // ② bind / register as usual …
   $c->definitions()->bind('answer', 42);

   // ③ first call populates the cache
   $val = $c->get('answer');          // ← resolves & stores

   // ④ subsequent calls – *even in a new PHP process* – are instant
   echo $c->get('answer');            // ← pulled straight from cache


Eager warm-up (a.k.a. *compile* the container):

.. code-block:: php

   $c->definitions()->cacheAllDefinitions(forceClearFirst: true);

``cacheAllDefinitions()`` iterates every current definition **once**,
resolves it and stores the result in:

1. **The configured file cache namespace**, and
2. The container’s in-process “resolved” map (so it is also fast in memory).

You can now deploy the warmed cache files between runs.

--------------------------------
Lazy Loading × Caching ⚡️
--------------------------------

Lazy loading (``enableLazyLoading(true)``) and caching work **together**:

* **Class / array bindings**
  → stored as a lightweight ``DeferredInitializer``
  → first ``get()`` constructs the object **and** writes it to cache.

* **User-supplied closures**
  → not wrapped in ``DeferredInitializer``
  → their resolved value is cached according to lifetime.

That means you may keep lazy loading **on** and still enjoy the *speed* of a
pre-warmed cache.

----------------------
Cache Invalidation 🔄
----------------------

* **forceClearFirst** in ``cacheAllDefinitions()`` clears InterMix keys **before**
  warming.
* Clear the namespace directory whenever you update service wiring and do not
  use force-clear warmup.

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
are always rebuilt (by design), so definition-result caching does not apply.

---------------------
One-liner Cheat-Sheet
---------------------

===========  =================================================================
Action        Code
===========  =================================================================
Enable cache  ``$c->definitions()->enableDefinitionCache('intermix')``
Warm all      ``$c->definitions()->cacheAllDefinitions()``
Clear + warm  ``…->cacheAllDefinitions(forceClearFirst:true)``
Disable       Omit ``enableDefinitionCache()``
===========  =================================================================

Next stop » :doc:`debug_tracing`
