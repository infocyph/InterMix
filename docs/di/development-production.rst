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

Self-contained deployments
--------------------------

A fully compiled graph can load without reconstructing its builder or attaching a dynamic fallback:

.. code-block:: php

   $runtime = ContainerBuilder::loadProductionArtifact(
       path: $path,
       expectedGraphIdentity: $deployment['intermix_graph'],
       expectedEnvironment: 'production',
   );

Record the ``graph`` returned by ``compile()`` in immutable deployment metadata.
The loader validates the runtime digest, build identity, ABI, PHP version, expected graph and environment.
Declared inputs compile natively and accept host-supplied seeds inside an active scope.
It rejects artifacts that need runtime factories, hooks or other fallback configuration.
Use the configured builder's ``production()`` for graphs that require fallback configuration.
Source-free runtimes also support ``make()`` and ``invoke()``. Their runtime
invoker retains the artifact's frozen injection, attribute and auto-binding
options and resolves declared dependencies through the compiled runtime.
It does not reconstruct the original builder or supply omitted dynamic definitions.

See :ref:`di.compiled-resolvers` for ABI compatibility and trusted prevalidated loading.

Reconfiguration
---------------

A finalized builder cannot be reopened. Build a new graph and install the new runtime only at a host-owned lifecycle boundary. Running containers are never mutated or deoptimized by later builder changes.
