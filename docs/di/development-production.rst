.. _di.development-production:

=============================
Dynamic and production modes
=============================

Dynamic runtime
---------------

build() returns Container from a finalized graph.

.. code-block:: php

   $runtime = $builder->build();

Use it in tests, local development, commands, or applications that do not need generated dispatch.

Production runtime
------------------

compile() generates an artifact and production() loads it:

.. code-block:: php

   $builder->compile($path);
   $runtime = $builder->production($path);

Both runtimes implement RuntimeContainerInterface and preserve the same definition, lifetime, scope, invocation, and error semantics.

Reconfiguration
---------------

A finalized builder cannot be reopened. Build a new graph and install the new runtime only at a host-owned lifecycle boundary. Running containers are never mutated or deoptimized by later builder changes.
