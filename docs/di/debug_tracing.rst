.. _di.debug_tracing:

================
Debug Tracing
================

When things don’t resolve the way you expect — **tracing** gives you eyes.

Turn on a **stack recorder** and see exactly what the container did when resolving
a class, function, or method.

------------------
Enable Tracing 🛠️
------------------

.. code-block:: php

   use Infocyph\InterMix\DI\Support\TraceLevelEnum;

   $c->options()->enableDebugTracing(true, TraceLevelEnum::Verbose);

Once tracing is enabled, every container action (like ``get()``, ``call()``, etc.)
is tracked with a per-service log.

---------------------
See the Trace Output 👀
---------------------

.. code-block:: php

   $c->get(MyService::class);               // resolve something
   print_r($c->debug(MyService::class));    // show resolution trace

Typical output:

.. code-block:: text

   [
     "class:MyService",
     "constructor() params",
     "def:LoggerInterface",
     "class:FileLogger",
   ]

This shows the chain of decisions InterMix followed:
- **constructor** called,
- **LoggerInterface** needed,
- it found a definition (``def:LoggerInterface``),
- resolved it to a class (``class:FileLogger``),
- and returned the instance.

----------------------
Trace Levels 📊
----------------------

Trace levels are available via the ``TraceLevelEnum`` enum:

.. list-table::
   :header-rows: 1
   :widths: 20 80

   * - Level
     - Description
   * - ``Off``
     - No trace at all
   * - ``Node``
     - DI node / definition boundaries (default threshold)
   * - ``Verbose``
     - Includes param names, fallback notices, env switches, etc

Additional levels are available for custom filtering: ``Error``, ``Warn`` and ``Info``.

-----------------------------
Check If a Trace Exists 🧠
-----------------------------

.. code-block:: php

   $entries = $c->tracer()->getEntries();
   if ($entries !== []) {
       $steps = $c->tracer()->toArray();
   }

Traces are only available **after resolution** and only if tracing was enabled
**before** the resolution occurred.

-------------------------
Use Cases & Tips 💡
-------------------------

✔ Great for **unit testing** service graphs
✔ Helpful when **debugging overrides** (env-specific bindings)
✔ Shows when fallback to autowiring or defaults occurs
✔ Reveals **why** something resolved or **why not**


Next stop » :doc:`cheat_sheet`
