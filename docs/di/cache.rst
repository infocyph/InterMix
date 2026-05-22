.. _di.cache:

========================
Definition-level Caching
========================

InterMix supports PSR-6 backed definition/cache warmup for safe cacheable definition data.
By default, live runtime objects are not persisted to PSR-6.
Singleton/scoped object reuse remains in the in-memory container lifetime store.

Why care?

* **Speed** – skip repeated reflection and definition work for cache-safe values.
* **Predictability** – warm and reuse cacheable definition data.
* **Flexibility** – use any PSR-6 implementation (for example from ``infocyph/cachelayer``).

---------------
Quick Example 🚀
---------------

.. code-block:: php

   use Infocyph\InterMix\DI\Container;
   use Psr\Cache\CacheItemPoolInterface;

   $c = Container::instance('intermix');

   /** @var CacheItemPoolInterface $pool */
   $pool = getYourPsr6Pool();

   // ① assign definition cache pool
   $c->definitions()->enableDefinitionCache($pool);

   // ② bind as usual
   $c->definitions()->bind('answer', 42);

   // ③ first call resolves + saves when the value is cache-safe
   $val = $c->get('answer');

   // ④ scalar/array-safe definitions may be loaded from PSR-6
   echo $c->get('answer');

Scalar/array-safe definitions may be persisted.
Runtime service objects are kept in container memory unless ``cacheRuntimeObjects`` is explicitly enabled.

Eager warm-up:

.. code-block:: php

   $c->definitions()->cacheAllDefinitions(forceClearFirst: true);

---------------------
One-liner Cheat-Sheet
---------------------

===========  =================================================================
Action        Code
===========  =================================================================
Assign pool   ``$c->definitions()->enableDefinitionCache($pool)``
Warm all      ``$c->definitions()->cacheAllDefinitions()``
Clear + warm  ``…->cacheAllDefinitions(forceClearFirst:true)``
Disable       Omit ``enableDefinitionCache(...)``
===========  =================================================================

Next stop » :doc:`debug_tracing`
