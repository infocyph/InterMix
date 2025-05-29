.. _serializer.value_serializer:

====================
ValueSerializer
====================

`Infocyph\InterMix\Serializer\ValueSerializer` is a minimal wrapper
around Opis Closure v4 that adds support for **resource** types.

Features
--------

- **serialize(mixed)** → string
- **unserialize(string)** → mixed
- **wrap(mixed)** → mixed (no blob)
- **unwrap(mixed)** → mixed
- **registerResourceHandler(type, wrapFn, restoreFn)**
- **clearResourceHandlers()** (for test/reset)

Example: Scalars & Arrays
-------------------------

.. code-block:: php

   use Infocyph\InterMix\Serializer\ValueSerializer;

   $blob = ValueSerializer::serialize([
       'id'   => 123,
       'tags' => ['php','cache'],
   ]);

   $data = ValueSerializer::unserialize($blob);
   // $data === ['id'=>123,'tags'=>['php','cache']]

Example: Closures
-----------------

.. code-block:: php

   $adder = fn(int $x): int => $x + 42;
   $blob  = ValueSerializer::serialize($adder);
   $call  = ValueSerializer::unserialize($blob);
   echo $call(8);  // outputs 50

Example: Manual wrap/unwrap
---------------------------

.. code-block:: php

   $arr     = ['foo','bar'];
   $wrapped = ValueSerializer::wrap($arr);
   // identical to $arr, but any resources inside would be preserved
   $orig    = ValueSerializer::unwrap($wrapped);
   // $orig === ['foo','bar']

Error on Unknown Resources
--------------------------

By default **no** resource handlers are registered.  Wrapping or
serialising a native resource (e.g. stream, curl, xml) with **no**
handler will throw:

.. code-block:: php

   $s = fopen('php://memory','r+');
   ValueSerializer::wrap($s);         // throws InvalidArgumentException
   ValueSerializer::serialize($s);    // also throws
