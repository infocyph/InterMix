.. _serializer:

=====================
Closure serialization
=====================

Closure serialization is optional and requires opis/closure.

Unsigned payloads are trusted executable input only. Signing authenticates the producer; it does not sandbox code.

.. code-block:: php

   use Infocyph\InterMix\Serializer\ClosureSerializer;

   $payload = ClosureSerializer::serialize(
       static fn (): string => 'hello',
   );

   $closure = ClosureSerializer::unserialize($payload);
   $result = $runtime->invoke($closure);

Signed mode
-----------

.. code-block:: php

   $serializer = ClosureSerializer::signed($key);
   $payload = $serializer->serialize(static fn () => 'trusted');
   $closure = $serializer->unserialize($payload);

Signed parsing validates size and HMAC before deserialization. Keep keys secret and rotate them through the host's normal secret-management process.

InterMix does not implicitly execute serialized payload strings. Deserialize explicitly, then invoke the resulting Closure through the runtime if execution is intended.
