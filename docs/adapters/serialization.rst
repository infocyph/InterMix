.. _cache.serialization:

=====================
Serialization Internals
=====================

All cache adapters rely on **`ValueSerializer`**
to handle arbitrary PHP values—scalars, arrays, closures and resources.

That component:

1. **Wraps** native PHP resources using user-registered handlers
2. Uses Opis Closure v4 (`serialize` / `unserialize`) to serialize closures
3. Produces a string blob via PHP’s native `serialize()` internally if no closures are involved
4. On fetch, **unwraps** resources via your handler and restores closures

Why is this necessary? PSR-6 requires that any stored value must be safely serializable if the adapter
stores it as a string or BLOB (Redis, Memcached, SQLite, File). If you want to cache:

* A **closure** (`fn(int $x) => $x + 5`)
* A **stream** (e.g. `fopen('php://memory','r+')`)
* A **cURL resource**, **XML parser**, or **GD image resource**, etc.

Then you must teach `ValueSerializer` how to handle that resource type by registering a handler:

.. code-block:: php

   use Infocyph\InterMix\Serializer\ValueSerializer;

   ValueSerializer::registerResourceHandler(
       'stream',
       function (mixed $res): array {
           if (!is_resource($res)) {
               throw new InvalidArgumentException('Expected resource');
           }
           $meta = stream_get_meta_data($res);
           rewind($res);
           return [
               'mode'    => $meta['mode'],
               'content' => stream_get_contents($res),
           ];
       },
       function (array $data): mixed {
           $s = fopen('php://memory', $data['mode']);
           fwrite($s, $data['content']);
           rewind($s);
           return $s;
       }
   );

See also:

:ref:`serializer.value_serializer`
:ref:`serializer.resource_handlers`
