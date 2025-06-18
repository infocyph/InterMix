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
       fn () => new BigService()
   );

With lazy loading **on**, the container does **not** immediately call the closure.
Instead, it stores a proxy (``DeferredInitializer``) that waits until ``get()``.

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
| Callable with no lifetime     | ✅ Yes                   |
+-------------------------------+--------------------------+
| Callable + `Lifetime::Transient` | ✅ Yes               |
+-------------------------------+--------------------------+
| User-supplied closure         | ❌ No – executes now     |
+-------------------------------+--------------------------+

---------------
Why not all?
---------------

User closures are resolved immediately to preserve intent. When you pass a closure
yourself, it’s assumed you want that logic executed *now*, not wrapped in another
proxy.

You can override this by wrapping your own closure in one:

.. code-block:: php

   $c->definitions()->bind('manual.lazy', fn () => fn () => new Heavy());

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

   use Infocyph\InterMix\DI\Support\TraceLevel;

   $c->options()->enableDebugTracing(true, TraceLevel::Verbose);

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
