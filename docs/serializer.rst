.. _serializer:

=====================
Closure Serialization
=====================

InterMix serializes only ``Closure`` objects, because ordinary PHP values already
have native serialization facilities. Resource serialization is application or
specialized-package responsibility.

Closure serialization is optional in InterMix 10. Install its runtime adapter
before using this page's APIs:

.. code-block:: bash

   composer require opis/closure

Applications that do not serialize closures do not load or execute Opis code.

Unsigned Closures
=================

.. code-block:: php

   use Infocyph\InterMix\Serializer\ClosureSerializer;

   $payload = ClosureSerializer::serialize(
       static fn (int $value): int => $value * 2,
   );

   if (ClosureSerializer::isEnvelope($payload)) {
       $closure = ClosureSerializer::unserialize($payload);
   }

Unsigned payloads use the versioned ``imxc1.`` envelope. Recognition is a
constant-time prefix check; it does not inspect Opis internals.
Deserialization rejects envelopes larger than the configured maximum before
base64 decoding. The default maximum payload size is 1 MiB.

Signed Closures
===============

Executable closures transported through a queue, database, cache, IPC, or a
remote system should be authenticated when the transport does not already
provide equivalent integrity protection.

.. code-block:: php

   $serializer = ClosureSerializer::signed($_ENV['APP_KEY']);
   $payload = $serializer->serialize(static fn (): string => 'work');
   $closure = $serializer->unserialize($payload);

Signed payloads use the ``imxcs2.`` envelope and HMAC-SHA3-256. Signing keys are
held by the serializer instance; InterMix has no process-global signing state.
Unsigned serializers reject signed payloads, signed serializers reject unsigned
payloads, and verification occurs before executable data is decoded.

Invocation Boundary
===================

``Invoker`` does not detect or execute serialized Closure payloads. Deserialize
at an explicit trusted boundary, then pass the resulting Closure to it:

.. code-block:: php

   $closure = ClosureSerializer::unserialize($payload);
   $result = $container->invocation()->invoke($closure);

Native closures, invokable objects, functions, static methods, and class strings
perform no Opis, payload-decoding, or HMAC work.
