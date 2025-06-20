.. _di.invoker:

=======================
Dynamic Invoker Utility
=======================

The ``Invoker`` class provides a convenience wrapper around the DI container to
simplify dynamic execution and object resolution.

--------
Overview
--------

.. code-block:: php

   use Infocyph\InterMix\Container;
   use Infocyph\InterMix\DI\Invoker;

   $invoker = Invoker::with(new Container());

-------------
Key Features
-------------

+------------------------+---------------------------------------------+
| Feature                | Description                                 |
+========================+=============================================+
| `invoke()`             | Dynamically call closures, classes, methods |
+------------------------+---------------------------------------------+
| `make()`               | Build object + optionally call a method     |
+------------------------+---------------------------------------------+
| `resolve()`            | Retrieve from container by key              |
+------------------------+---------------------------------------------+
| `serialize()`          | Serialize closures and values               |
+------------------------+---------------------------------------------+
| `unserialize()`        | Restore serialized closures or data         |
+------------------------+---------------------------------------------+

---------------
Usage Examples
---------------

**1. Call a closure**

.. code-block:: php

   $result = $invoker->invoke(fn () => 'hello');

**2. Call a class method**

.. code-block:: php

   $result = $invoker->invoke([MyService::class, 'boot']);

**3. Serialize and restore a closure**

.. code-block:: php

   $packed = $invoker->serialize(fn () => 42);
   $fn = $invoker->unserialize($packed);
   echo $fn(); // 42

**4. Shared global instance**

.. code-block:: php

   $invoker = Invoker::shared();
   $data = $invoker->resolve('service');

---------
Internals
---------

The invoker uses:

- `routeCallable()` — detects callable types: closures, invokable classes, strings, or serialized closures
- `viaClosure()` — injects closures into the container for contextual execution
- Integration with `ValueSerializer` for full closure support

--------

Next up → :ref:`di.attribute`
