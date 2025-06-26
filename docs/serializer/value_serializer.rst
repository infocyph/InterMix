.. _serializer.value_serializer:

====================
ValueSerializer
====================

``ValueSerializer`` is a thin wrapper around **Opis Closure v4** that adds
first-class support for PHP **resources** and now includes
**transport-friendly helpers**.

Under the hood it relies on:

1. Opis Closure’s ``serialize()`` / ``unserialize()`` for closures,
   anonymous classes & deep object graphs.
2. A **plugin system** (“resource handlers”) that can *wrap* any PHP
   **resource** into plain data and *restore* it on the way back.
3. A tiny **memo-cache** inside :php:meth:`isSerializedClosure()` to detect
   Opis payloads with **O(1)** string checks.

Why?
----

Plain PHP ``serialize()`` chokes on closures ***and*** resources.
**ValueSerializer** solves both:

* **Closures & objects** → delegated to Opis Closure.
* **Resources** → you register *wrap* / *restore* callbacks.

Everything becomes a safe string, ready for caches, queues, cookies, etc.

Public API
----------

.. php:function:: string ValueSerializer::serialize(mixed $value)

   Convert any PHP value into a binary string.
   Throws ``InvalidArgumentException`` if an un-handled resource is found.

.. php:function:: mixed ValueSerializer::unserialize(string $blob)

   Reverse of ``serialize()`` – returns the original value.

.. php:function:: string ValueSerializer::encode(mixed $value, bool $base64 = true)

   – Convenience wrapper around ``serialize()``.
   If ``$base64`` is *true* (default) the binary blob is passed through
   ``base64_encode()`` – perfect for URLs, JSON, headers, etc.

.. php:function:: mixed ValueSerializer::decode(string $payload, bool $base64 = true)

   – Rebuild a value produced by ``encode()``.
   Decodes base64 (when enabled) and forwards to ``unserialize()``.

.. php:function:: bool ValueSerializer::isSerializedClosure(string $str)

   Cheap Opis payload detector used by *Invoker*.
   Internally memo-cached for the lifetime of the request.

.. php:function:: mixed ValueSerializer::wrap(mixed $value)

   Wrap resources **only** (no string conversion).

.. php:function:: mixed ValueSerializer::unwrap(mixed $value)

   Reverse of ``wrap()``.

.. php:function:: void ValueSerializer::registerResourceHandler(string $type, callable $wrapFn, callable $restoreFn)

   Register a new resource handler.

.. php:function:: void ValueSerializer::clearResourceHandlers()

   Drop all previously registered handlers (handy in tests).

Usage Examples
--------------

Serialize / Unserialize
~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   use Infocyph\InterMix\Serializer\ValueSerializer;

   $adder  = fn (int $x) => $x + 10;
   $stream = fopen('php://memory', 'r+');     // will need a handler

   // Register a simple stream handler (see docs below) …
   // ValueSerializer::registerResourceHandler('stream', $wrap, $restore);

   $blob = ValueSerializer::serialize([$adder, $stream]);
   $same = ValueSerializer::unserialize($blob);

Encode / Decode (base64)
~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $payload = ['id' => 42, 'cb' => fn() => 'hi'];

   $token   = ValueSerializer::encode($payload);   // base64 by default
   $clone   = ValueSerializer::decode($token);

   echo ($clone['cb'])();   // "hi"

Manual wrap / unwrap
~~~~~~~~~~~~~~~~~~~~

See *wrap()* / *unwrap()* example in the original docs – unchanged.

Registering a Resource Handler
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

*(Identical to previous version – shown here abbreviated)*

.. code-block:: php

   ValueSerializer::registerResourceHandler(
       'stream',
       fn ($res) => /* …wrap… */ ,
       fn ($data) => /* …restore… */
   );

