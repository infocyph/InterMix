.. _di.compiled-resolvers:

================================================
Compiled runtimes
================================================

Compilation consumes the same finalized DefinitionGraph used by dynamic build().

.. code-block:: php

   $builder = ContainerBuilder::create('app')
       ->releaseIdentity('release-2026-10-01')
       ->autowire(Logger::class, JsonLogger::class)
       ->autowire(Service::class, Service::class);

   $report = $builder->compile(
       __DIR__ . '/var/intermix.php',
       strict: true,
   );

   $runtime = $builder->production(__DIR__ . '/var/intermix.php');

Strict and hybrid modes
-----------------------

Strict compilation rejects unsupported definitions.

Hybrid compilation records frozen fallback requirements explicitly. Runtime-only closures or opaque metadata are not silently serialized into generated PHP.

Declared scoped inputs
----------------------

Inputs are native compiled entries. Declaring an input does not require a dynamic
fallback or a release identity. Its consumers and aliases can also compile when
their remaining features are supported.

.. code-block:: php

   $builder = ContainerBuilder::create('app')
       ->input(ExecutionId::class);
   $report = $builder->compile($path, strict: true);

   // At bootstrap, source the expected graph from immutable deployment metadata.
   $runtime = ContainerBuilder::loadProductionArtifact($path, $deployment['intermix_graph']);
   $runtime->withinScope(
       'job',
       static fn ($active) => $active->get(ExecutionId::class),
       [ExecutionId::class => $executionId],
   );

Record the report's graph identity in deployment metadata during the build.
Input values belong to the active scope. Missing inputs, undeclared seeds and
resolution outside a scope fail closed. Nested scopes require their own seeds;
explicit scope-context attachments share their owner's seeds. Singleton
construction cannot capture a scoped input.

Runtime factories and unsupported lifecycle hooks retain their frozen fallback
and matching release identity requirements. Existing manifest validation also
applies to input-bearing artifacts.

Validation
----------

Production loading validates InterMix major/ABI, PHP major/minor, source digest, graph identity, environment identity, release identity, fallback metadata, artifact identity, and build identity before activation.

Publication
-----------

Generated output is staged as a complete versioned build and activated atomically. Publish complete build directories, then switch traffic. Treat generated PHP and metadata as trusted deployment artifacts.

Prevalidated loading
--------------------

productionPrevalidated(path, digest) is an explicit trusted-deployment optimization. The caller is responsible for protecting the validated artifact/digest pair.
