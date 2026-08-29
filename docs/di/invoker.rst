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

   use Infocyph\InterMix\DI\Container;
   use Infocyph\InterMix\DI\Invoker;

   $invoker = Invoker::with(new Container());

-------------
Key Features
-------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Feature
     - Description
   * - ``invoke()``
     - Dynamically call closures, classes, methods
   * - ``make()``
     - Build object plus optionally call a method
   * - ``resolve()``
     - Retrieve from container by key
   * - ``callableFor()``
     - Resolve an invokable class for immediate use

---------------
Usage Examples
---------------

**1. Call a closure**

.. code-block:: php

   $result = $invoker->invoke(fn () => 'hello');

**2. Call a class method**

.. code-block:: php

   $result = $invoker->invoke([MyService::class, 'boot']);

Callable static methods are executed directly without constructing the
declaring class. Non-static ``[ClassName::class, 'method']`` targets still use
the container so constructor and method dependencies can be resolved.

**3. Serialize and restore a closure**

This optional example requires ``composer require opis/closure``.

.. code-block:: php

   use Infocyph\InterMix\Serializer\ClosureSerializer;

   $packed = ClosureSerializer::serialize(fn () => 42);
   $fn = ClosureSerializer::unserialize($packed);
   echo $fn(); // 42

**4. Shared global instance**

.. code-block:: php

   $invoker = Invoker::shared();
   $data = $invoker->resolve('service');

---------
Internals
---------

The invoker routes common callables in this order:

- native closures
- invokable objects
- callable arrays
- function strings
- static ``Class::method`` strings
- class strings

Normal callable routing performs no Opis, payload decoding, signing, or HMAC
work. Serialization remains the responsibility of ``ClosureSerializer``.

--------

Next up → :ref:`di.attribute`
