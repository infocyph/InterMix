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

Validation
----------

Production loading validates InterMix major/ABI, PHP major/minor, source digest, graph identity, environment identity, release identity, fallback metadata, artifact identity, and build identity before activation.

Publication
-----------

Generated output is staged as a complete versioned build and activated atomically. Publish complete build directories, then switch traffic. Treat generated PHP and metadata as trusted deployment artifacts.

Prevalidated loading
--------------------

productionPrevalidated(path, digest) is an explicit trusted-deployment optimization. The caller is responsible for protecting the validated artifact/digest pair.
