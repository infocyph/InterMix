.. _serializer.value_serializer:

====================
ValueSerializer
====================

`ValueSerializer` is a thin wrapper around
**Opis Closure v4** that adds first‐class support for PHP **resources**.
Under the hood it uses

  1. `serialize()` / `unserialize()` to handle closures, anonymous classes, object graphs, etc.
  2. A **plugin system** (“resource handlers”) to wrap any PHP resource into a PHP array (or other serializable form) and to restore it at unserialize time.

Why?
----

By default, PHP’s `serialize()` cannot handle closures or resources (like
streams, sockets, cURL handles, XML parsers, GD images, etc.).
**ValueSerializer** solves both:

- **Closures & object graphs**: delegated to Opis Closure v4.
- **Resource types**: you register callbacks to “wrap” them into plain data
  (e.g. read a stream’s metadata + contents) and “restore” them (e.g. re‐open
  a `php://memory` stream and inject the original bytes).

As a result, any PHP value (scalars, arrays, objects, closures, resources)
becomes a simple `string` when you call **`ValueSerializer::serialize($v)`**,
and you get the original PHP value back with
**`ValueSerializer::unserialize($blob)`**.

Public API
----------

.. py:function:: string ValueSerializer::serialize(mixed $value)

   Recursively wraps any embedded resource via registered handlers, then
   serializes the entire structure using Opis Closure.
   Throws `InvalidArgumentException` if a PHP resource appears for which no
   handler is registered.

   - **$value**: any PHP value (scalar, array, object, closure, resource).
   - **Returns**: a `string` blob.

.. py:function:: mixed ValueSerializer::unserialize(string $blob)

   Reverses `serialize()`: uses Opis Closure’s `unserialize`, then recursively
   unwraps any previously wrapped resource via your handlers.

   - **$blob**: a string produced by `ValueSerializer::serialize()`.
   - **Returns**: the original PHP value, with resources re‐instantiated.

.. py:function:: mixed ValueSerializer::wrap(mixed $value)

   Convenience to “wrap only”—no actual string serialization takes place.
   Returns the same `$value` structure, but any resource is replaced by
   an array of the form::

       [
         "__wrapped_resource" => true,
         "type"               => "<resource type>",
         "data"               => <whatever your wrapFn returned>
       ]

   Useful if you want to inspect or store the intermediate form.

.. py:function:: mixed ValueSerializer::unwrap(mixed $resource)

   Reverses `wrap()` only—no string deserialization.
   Finds any array nodes tagged with `__wrapped_resource = true`, looks up the
   registered “restore” callback, and returns a real PHP resource.

.. py:function:: void ValueSerializer::registerResourceHandler(
       string $type,
       callable $wrapFn,
       callable $restoreFn
   )

   Register a new handler for a resource of type `$type` (as returned by
   `get_resource_type()`).

   - **$type**: e.g. `"stream"`, `"gd"`, `"curl"`, `"xml"`.
   - **$wrapFn**: `fn(resource $r): mixed` → should return a PHP‐serializable
     array or scalar that captures all needed metadata.
   - **$restoreFn**: `fn(mixed $data): resource` → takes what you returned
     from `wrapFn` and must re‐create the resource.

   Throws `InvalidArgumentException` if you attempt to register a second
   handler for the same `$type`.

.. py:function:: void ValueSerializer::clearResourceHandlers()

   Remove all previously registered resource handlers.
   Useful for resetting state during tests.

Usage Examples
--------------

Scalars & Arrays
~~~~~~~~~~~~~~~~

.. code-block:: php

   use Infocyph\\InterMix\\Serializer\\ValueSerializer;

   $values = [
       123,
       'abc',
       [1, 2, 3],
       ['nested' => ['x' => true, 'y' => 2]],
   ];

   foreach ($values as $v) {
       $blob = ValueSerializer::serialize($v);
       $out  = ValueSerializer::unserialize($blob);
       // $out === $v
   }

Closures
~~~~~~~~

Supported out of the box—no extra setup required:

.. code-block:: php

   use Infocyph\\InterMix\\Serializer\\ValueSerializer;

   $adder = fn(int $x): int => $x + 42;
   $blob  = ValueSerializer::serialize($adder);
   $call  = ValueSerializer::unserialize($blob);
   echo $call(8);   // outputs 50

Manual wrap/unwrap (no full serialisation)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

If you only need to “wrap” a data structure (e.g. before saving to some
other medium) without actually turning it into a string, use `wrap()` / `unwrap()`:

.. code-block:: php

   $arr     = ['foo', 'bar', fopen('php://memory','r+')];
   // no resource handler registered yet for stream:
   try {
       ValueSerializer::wrap($arr);
   } catch (InvalidArgumentException $e) {
       echo $e->getMessage();  // “No handler for resource type 'stream'”
   }

Registering a Resource Handler
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

By default, **no** resource handlers exist.  You must register one before you
attempt to serialise or wrap a native PHP resource.

Example: **Stream** handler

.. code-block:: php

   use Infocyph\\InterMix\\Serializer\\ValueSerializer;

   ValueSerializer::registerResourceHandler(
       'stream',
       // ------------ wrapFn ---------------------------------------
       function (resource $res): array {
           $meta = stream_get_meta_data($res);
           rewind($res);
           return [
               'mode'    => $meta['mode'],
               'content' => stream_get_contents($res),
           ];
       },
       // ---------- restoreFn -------------------------------------
       function (array $data): resource {
           $s = fopen('php://memory', $data['mode']);
           fwrite($s, $data['content']);
           rewind($s);
           return $s;  // real resource returned
       }
   );

Now you can serialise a stream:

.. code-block:: php

   $fp   = fopen('php://memory', 'r+');
   fwrite($fp, 'hello'); rewind($fp);

   // wrap only (no string serialization)
   $wrapped = ValueSerializer::wrap($fp);
   // returns ['__wrapped_resource'=>true,'type'=>'stream','data'=> ['mode'=>'r+','content'=>'hello']]

   // full serialize to string
   $blob = ValueSerializer::serialize($fp);

   // recover resource
   $restored = ValueSerializer::unserialize($blob);
   echo stream_get_contents($restored);  // “hello”

Error: Unknown Resource
~~~~~~~~~~~~~~~~~~~~~~~

If you call `wrap()` or `serialize()` on a resource for which no handler was
registered, **ValueSerializer** throws an `InvalidArgumentException`:

.. code-block:: php

   $fd = fopen('php://memory', 'r+');
   // no handler for 'stream' ⇒ exception:
   ValueSerializer::serialize($fd);

Clearing Registered Handlers (Testing)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

In your test suite, you can reset the serializer to a “clean” state:

.. code-block:: php

   use Infocyph\\InterMix\\Serializer\\ValueSerializer;

   ValueSerializer::clearResourceHandlers();
