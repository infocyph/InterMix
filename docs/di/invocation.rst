.. _di.invocation:

================================================
Runtime invocation
================================================

Runtime invocation is explicit in InterMix 11.

make()
------

make() constructs a fresh class instance and accepts positional or named constructor arguments.

.. code-block:: php

   $job = $runtime->make(Job::class, ['name' => 'nightly']);

invoke()
--------

invoke() executes a real PHP callable and resolves missing typed parameters through the runtime.

.. code-block:: php

   $handler = $runtime->make(Handler::class);

   $result = $runtime->invoke(
       [$handler, 'handle'],
       ['payload' => $payload],
   );

Functions, closures, invokable objects, and valid callable arrays are supported. String descriptor parsing and implicit default methods are not part of the 11.0 runtime contract.

PSR-11
------

Use get() for configured/resolvable services and has() for the PSR-11 resolvability check.
