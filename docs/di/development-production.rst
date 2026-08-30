.. _di.development_production:

====================================
Development and Production Workflows
====================================

InterMix 10 has two intentional DI execution paths. Use the dynamic
``Container`` while developing and diagnosing a graph; finalize that same graph
through ``ContainerBuilder`` for a generated ``ProductionContainer`` in
production.

Choosing ``setEnvironment('prod')`` does **not** select the production runtime.
The environment name chooses conditional bindings and metadata. The consuming
application explicitly chooses the runtime at its composition root.

Which path should I use?
------------------------

.. list-table::
   :header-rows: 1
   :widths: 22 36 42

   * - Concern
     - Development
     - Production
   * - Runtime
     - Dynamic ``Container`` from ``$builder->development()``
     - Generated ``ProductionContainer`` from ``production()`` or
       ``productionPrevalidated()``
   * - Main goal
     - Fast iteration, diagnostics and mutable configuration
     - Finalized graph, direct generated calls and predictable hot paths
   * - Compilation
     - Not required
     - Run once during build/deployment, never inside a live request or job
   * - Tracing
     - Enable when diagnosing resolution
     - Keep off; inspect build validation and compilation reports instead
   * - Configuration changes
     - Allowed before optional ``lock()``
     - Produce a new artifact and deploy it
   * - Dynamic behavior
     - Resolved directly by the dynamic engine
     - Kept in narrow lazy compatibility islands

Keep one configuration function
-------------------------------

Build the graph in one application-owned function so development, the release
compiler, and production bootstrap do not drift:

.. code-block:: php

   use Infocyph\InterMix\DI\ContainerBuilder;

   function applicationContainer(string $environment): ContainerBuilder
   {
       return ContainerBuilder::create('application')
           ->setEnvironment($environment)
           ->singleton(LoggerInterface::class, JsonLogger::class)
           ->singleton(Database::class)
           ->scoped(RequestContext::class)
           ->singleton(Application::class);
   }

Packages should contribute definitions or a service provider. The host
application owns environment selection, artifact paths, compilation and runtime
selection.

Development bootstrap
---------------------

Use the dynamic container directly. Reflection, attributes, tracing and mutable
registration remain available:

.. code-block:: php

   $builder = applicationContainer('local');
   $container = $builder->development();

   $container->options()->enableDebugTracing();
   $application = $container->get(Application::class);

Use a distinct container alias per isolated test or independently configured
worker. For request/job state, use ``withinScope()`` so the scope is always
left. A shared container isolates scoped state between active PHP ``Fiber``
instances and Swoole/OpenSwoole coroutines; its singleton services and
configuration are still shared.

Production build step
---------------------

Run this in CI, a release image build, or an explicit cache-warm command:

.. code-block:: php

   $path = __DIR__ . '/bootstrap/cache/intermix.production.php';
   $builder = applicationContainer('prod');

   $builder->validate(strict: true);
   $report = $builder->compile($path);

   foreach ($report['skipped'] as $id => $reason) {
       // Review intentional dynamic islands during the release build.
       fwrite(STDERR, "$id remains dynamic: $reason\n");
   }

Publish all of the following as one release:

* the generated PHP artifact;
* its adjacent ``.meta.json`` manifest; and
* application code and dependencies from the same build.

The report's ``sha256`` may also be recorded in trusted immutable deployment
metadata for prevalidated loading. A database row, cache value, environment
variable, or file writable by the runtime worker is not automatically a trusted
digest source.

Production bootstrap
--------------------

Recreate the same configuration graph so dynamic islands have their normal
fallback definitions, then load the artifact once during process bootstrap:

.. code-block:: php

   $path = __DIR__ . '/bootstrap/cache/intermix.production.php';
   $builder = applicationContainer('prod');

   // Safe default: validates ABI, manifest, environment and file SHA-256.
   $container = $builder->production($path);
   $application = $container->get(Application::class);

When the deployment system has already validated the immutable artifact, it can
avoid hashing the file in every new process:

.. code-block:: php

   $trustedSha256 = deploymentMetadata('intermix.sha256');
   $container = $builder->productionPrevalidated($path, $trustedSha256);

``productionPrevalidated()`` is not a shortcut for reading the digest from the
same mutable artifact directory. If the trust boundary is uncertain, use
``production()``.

Why production still has a fallback graph
-----------------------------------------

Not every PHP value can be safely emitted as static source. Closure definitions,
direct factories, custom runtime attributes, arbitrary callables, runtime
parameters and unregistered autowirable classes remain dynamic. The generated
runtime creates its optimized resolver machinery only when one of those islands
is actually used. Compiled-to-dynamic bridges preserve singleton and scoped
identity across the boundary.

Mutation and redeployment rules
-------------------------------

Treat a loaded production graph as finalized:

* Any configuration mutation through the builder, a retained manager, or the
  development container deoptimizes an active runtime safely.
* After finalization changes, that builder refuses to load the stale artifact
  again until ``compile()`` succeeds.
* Loading another production runtime from the same builder deoptimizes the
  previous runtime before replacing fallback bridges.
* Use separate builders when multiple generated runtimes must remain active at
  the same time.

Deoptimization protects correctness during an exceptional late mutation; it is
not a deployment strategy. Normal production changes should build, validate and
publish a new immutable artifact, then replace worker processes cleanly.

Testing both paths
------------------

Most unit tests can use ``development()`` for speed and diagnostics. Add parity
tests for the production boundary when a graph uses scopes, tags, lifecycle
hooks, attributes, contextual/environment bindings, dynamic islands or unusual
callables:

.. code-block:: php

   $development = applicationContainer('test')->development();

   $productionBuilder = applicationContainer('test');
   $report = $productionBuilder->compile($temporaryPath);
   $production = $productionBuilder->productionPrevalidated(
       $temporaryPath,
       $report['sha256'],
   );

   expect($production->get(Application::class))
       ->toEqual($development->get(Application::class));

Compare observable behavior, not object identity across two independent
containers. Also test the compiled report so every skipped definition is an
intentional compatibility island.

What about ``Container::compileTo()``?
--------------------------------------

``Container::compileTo()`` and ``useCompiled()`` remain the compatible
dynamic-container resolver-map feature. Use them when the application must keep
the mutable ``Container`` API as its runtime. For the InterMix 10 production hot
path, prefer ``ContainerBuilder::compile()`` and ``ProductionContainer``.

Next steps
----------

* :doc:`compiled-resolvers` – compilation eligibility, manifests and dynamic islands.
* :doc:`environment` – environment-specific bindings and metadata.
* :doc:`scopes` – request/job isolation for persistent workers.
* :doc:`debug_tracing` – development diagnostics.
