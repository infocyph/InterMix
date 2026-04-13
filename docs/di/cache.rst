.. _di.cache:

========================
Definition-level Caching
========================

InterMix supports caching of resolved singleton definitions through an assigned
PHP-FIG PSR-6 cache pool.

Why care?

* **Speed** – skip repeated reflection and factory execution.
* **Predictability** – warm and reuse resolved singleton entries.
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

   // ③ first call resolves + saves
   $val = $c->get('answer');

   // ④ next calls are read from cache
   echo $c->get('answer');

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
