.. _cache.serialization:

=====================
Serialization Internals
=====================

All cache adapters rely on **`Infocyph\InterMix\Serializer\ValueSerializer`**
to handle arbitrary PHP values—scalars, arrays, closures, and even resources.

That component “wraps” any native PHP resource using a user‐registered handler,
then uses Opis Closure v4 (`oc_serialize/oc_unserialize`) to produce a string
blob. When loading back, it “unwraps” resources via your handler, restores
closures, and returns exactly what you put in.

Why? PSR-6 requires that a value be serializable if the adapter is storing
it as a string or BLOB (Redis, Memcached, SQLite, File). If you want to cache:

* A **closure** (`fn(int $x) => $x + 5`)
* A **stream** (e.g. `fopen('php://memory','r+')`)
* A **Curl resource**, **XML parser**, **GD image resource**, etc.

Then you must teach `ValueSerializer` how to handle that resource type.

:ref:`serializer.value_serializer`
:ref:`serializer.resource_handlers`
