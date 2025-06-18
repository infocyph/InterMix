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

   use Infocyph\InterMix\DI\Support\TraceLevel;

   $c->options()->enableDebugTracing(true, TraceLevel::Verbose);

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

Three levels are available via the ``TraceLevel`` enum:

+----------------+-----------------------------------------------------------+
| Level          | Description                                               |
+================+===========================================================+
| ``Off``        | No trace at all (default)                                 |
+----------------+-----------------------------------------------------------+
| ``Compact``    | One-line per resolution step                              |
+----------------+-----------------------------------------------------------+
| ``Verbose``    | Includes param names, fallback notices, env switches, etc |
+----------------+-----------------------------------------------------------+

-----------------------------
Check If a Trace Exists 🧠
-----------------------------

.. code-block:: php

   if ($c->hasDebug(MyService::class)) {
       $steps = $c->debug(MyService::class);
   }

Traces are only available **after resolution**, and only if tracing was enabled
**before** the resolution occurred.

-------------------------
Use Cases & Tips 💡
-------------------------

✔ Great for **unit testing** service graphs
✔ Helpful when **debugging overrides** (env-specific bindings)
✔ Shows when fallback to autowiring or defaults occurs
✔ Reveals **why** something resolved or **why not**

----------------------
Disable or Reset 🧹
----------------------

To turn off tracing again:

.. code-block:: php

   $c->options()->enableDebugTracing(false);

To clear trace logs manually:

.. code-block:: php

   $c->resetDebug();


Next stop » :doc:`psr_support`

