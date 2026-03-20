.. _di.lazy_loading:

================
Lazy Loading
================

Lazy loading delays service construction until the **first time** you access it.
Instead of creating the object right away, InterMix stores a lightweight
:php:class:`DeferredInitializer`.

When enabled (default), this reduces **startup cost** for services that might
never be used in a request or command.

----------------
How It Works ⚙️
----------------

.. code-block:: php

   $c->definitions()->bind(
       'expensive',
       BigService::class
   );

With lazy loading **on**, the container stores a proxy (``DeferredInitializer``)
for class/array-style definitions and resolves it when ``get()`` is first called.

-----------------------------------------
When does InterMix create the instance?
-----------------------------------------

* On your first call to ``$c->get('expensive')``
* If another service depends on it via autowiring or attribute
* If preloading or caching is used (`resolveDefinition()` internally)

---------------
Default Rules
---------------

+-------------------------------+--------------------------+
| Definition type               | Lazy by default?         |
+===============================+==========================+
| Class / string                | ✅ Yes                   |
+-------------------------------+--------------------------+
| Array definition              | ✅ Yes                   |
+-------------------------------+--------------------------+
| User closure + Singleton/Scoped | ⚠️ Resolved on first ``get()`` (no DeferredInitializer) |
+-------------------------------+--------------------------+
| User closure + Transient      | ❌ No caching (runs each ``get()``) |
+-------------------------------+--------------------------+

---------------
Why not all?
---------------

User closures are not wrapped in ``DeferredInitializer``. They run when the
service is resolved, and their reuse depends on lifetime (singleton/scoped cache
the resolved value; transient does not).

--------------------
Enable or Disable 🔧
--------------------

.. code-block:: php

   $c->options()->enableLazyLoading(true);   // on (default)
   $c->options()->enableLazyLoading(false);  // turn off

---------------------
Debugging 🐞
---------------------

To see lazy resolutions in action:

.. code-block:: php

   use Infocyph\InterMix\DI\Support\TraceLevelEnum;

   $c->options()->enableDebugTracing(true, TraceLevelEnum::Verbose);

Then inspect resolution paths for markers like ``[lazy-init]`` or ``[deferred]``.

---------------------
Best Practices 💡
---------------------

* Leave lazy loading **on** unless you're doing eager preloading
* Use it with **scoped** services for maximum gain (e.g. per request objects)
* Consider disabling during unit tests to catch misconfigurations early

See also:

* :doc:`scopes` – lazy services are unique per scope if their lifetime is Scoped
* :doc:`lifetimes` – for controlling instancing behavior
