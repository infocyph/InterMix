.. _di.compiled_resolvers:

==================
Compiled Resolvers
==================

Compiled resolvers move stable construction analysis to the build or deployment
stage. They replace only a service's construction recipe. Lifetimes, scopes,
hooks, tracing, cycle detection, and normal container error handling remain in
the regular resolution lifecycle.

InterMix 10 also provides a finalized production runtime. Use
``ContainerBuilder`` for the maximum-performance generated container described
below. The later ``Container::compileTo()`` sections document the compatible
dynamic-container resolver map, which remains available when applications need
the mutable ``Container`` API at runtime.

InterMix 10 Production Runtime
------------------------------

Configure the graph through ``ContainerBuilder``. Development can use the
underlying dynamic container without compilation:

.. code-block:: php

   use Infocyph\InterMix\DI\ContainerBuilder;

   $builder = ContainerBuilder::create()
       ->setEnvironment('production')
       ->singleton(Logger::class, JsonLogger::class)
       ->singleton(Mailer::class);

   $development = $builder->development();

Compile during the build or release stage, then load the generated runtime from
the immutable release:

.. code-block:: php

   $path = __DIR__ . '/bootstrap/cache/intermix.production.php';
   $report = $builder->compile($path);

   $container = $builder->productionPrevalidated(
       $path,
       $report['digest'],
   );

``production($path)`` verifies the artifact with ``hash_file('xxh128', ...)``
before loading. ``productionPrevalidated($path, $digest)`` is for deployment
systems that already verified the immutable artifact and obtained its xxh128
digest from trusted deployment metadata. It validates the manifest and expected
digest without hashing the PHP file on every process start.

The generated runtime specializes known service IDs, lifetimes, scopes, tags,
deterministic injection, and known invocation paths. Closures, custom runtime
attributes, arbitrary callables, and unregistered autowirable classes remain
available through lazy compatibility islands. Production never compiles because
an artifact is missing.

The artifact manifest records the environment selected during compilation.
Loading it against a differently configured builder fails before executing the
artifact. Any builder configuration mutation after compilation invalidates the
finalization and requires another ``compile()`` before production can be loaded.
This includes mutations performed through a manager or development-container
reference obtained earlier; an already active runtime deoptimizes before the
new configuration becomes authoritative.
Loading a second production runtime from one builder first deoptimizes the prior
runtime so fallback bridges cannot chain across runtimes. Use independent
builders when several production containers must remain active simultaneously.

The existing ``Container`` remains the fully dynamic/development API. Choose the
runtime model at the composition root; application services should normally
depend on PSR-11 or on their concrete dependencies, not on ``ContainerBuilder``.

Build And Load
--------------

Register the complete container before compiling. At runtime, repeat the same
registration and load the artifact only afterward.

.. code-block:: php

   use Infocyph\InterMix\DI\Container;

   $container = Container::instance('application');
   registerApplicationServices($container);

   $path = __DIR__ . '/bootstrap/cache/intermix.php';
   $container->compileTo($path);

   $report = $container->compilationReport();
   foreach ($report['skipped'] as $id => $reason) {
       printf("%s remains dynamic: %s\n", $id, $reason);
   }

The runtime composition root loads the generated file after registration:

.. code-block:: php

   $container = Container::instance('application');
   registerApplicationServices($container);
   $container->useCompiled(__DIR__ . '/bootstrap/cache/intermix.php');

``compileTo($path, load: true)`` is available for tests, tools, and processes
that build and consume the artifact in the same container.

Artifact Location
-----------------

InterMix does not choose or own the artifact location. The application or
framework supplies the path to both ``compileTo()`` and ``useCompiled()``. A
framework can therefore keep the artifact alongside its other deployment
caches, for example ``bootstrap/cache/container.php``, and expose its own
configuration or environment override. Use the same resolved path for cache
generation, runtime loading, and cache clearing.

The parent directory must be writable during cache generation. Runtime workers
need only read the published artifact. Do not place it in ``vendor/`` or derive
the path from request input.

Declarative Factories
---------------------

Ordinary closures can capture objects, resources, mutable state, and runtime
context. InterMix therefore never converts a closure or ``DirectFactory`` into
generated PHP. Use an explicit declarative definition when every input can be
represented as a service reference or an exportable value.

.. code-block:: php

   use Infocyph\InterMix\DI\Support\FactoryDefinition;
   use Infocyph\InterMix\DI\Support\LifetimeEnum;
   use Infocyph\InterMix\DI\Support\ServiceReference;

   $container->bind(
       'report.mailer',
       FactoryDefinition::construct(Mailer::class, [
           new ServiceReference(LoggerInterface::class),
           'reports',
           ['retry' => 2, 'enabled' => true],
       ]),
       LifetimeEnum::Scoped,
   );

A public static factory is also supported:

.. code-block:: php

   $container->bind(
       'report.mailer',
       FactoryDefinition::staticFactory(Mailer::class, 'create', [
           new ServiceReference(LoggerInterface::class),
           'reports',
       ]),
   );

Arguments are positional. Literal arguments may contain ``null``, scalars, or
recursively exportable arrays. Objects, resources, closures, and associative
argument maps are rejected when the definition is created. Service IDs are
always explicit through ``ServiceReference``.

Automatic Eligibility And Fallback
----------------------------------

Class-string definitions are compiled only when cache-time reflection proves
that direct construction has the same behavior as dynamic resolution. A class
remains dynamic when any of these conditions applies:

* constructor, property, or method resources were registered for the class;
* a constructor dependency has a contextual binding or is shadowed by a
  parameter-name definition;
* constructor parameters use attributes, union or intersection types,
  variadics, repeated dependency types, or values that cannot be exported;
* enabled property injection can resolve a built-in or registered attribute;
* resolution would invoke ``CALL_ON`` (or legacy ``callOn``), the configured
  default method, or ``__invoke()``.

These checks run only while generating the cache. An ineligible definition is
omitted from the generated resolver map, and its precise reason appears in
``compilationReport()['skipped']``. Requests do not repeat the eligibility
checks: a compiled closure runs directly, while an omitted entry follows the
unchanged dynamic resolver.

An explicit ``FactoryDefinition`` is authoritative. Use it when application
composition deliberately chooses an exact constructor or static-factory recipe
and wants reflection-free resolution despite other class capabilities.

Artifact Safety
---------------

Generated files contain an artifact format version and fingerprints for:

* the PHP major and minor version;
* the installed InterMix version and package reference;
* the selected container environment;
* the complete resolution-relevant compiled-configuration identity;
* definition IDs and resolution-affecting resource, context, attribute, and
  implicit-method configuration;
* every compiled recipe;
* effective lifetime and tag metadata.

Generation validates the staged PHP file before atomically activating it.
``useCompiled()`` validates the entire artifact before installing any resolver.
A missing definition, changed recipe, changed environment, or incompatible
runtime causes a ``ContainerException`` and requires a rebuild. Runtime
validation deliberately avoids reflection and source-file hashing; rebuild the
artifact for every immutable application release so source-only changes cannot
reuse an older recipe. Treat the cache file as trusted executable build output
and never accept its path or contents from a request.

The fingerprints are compatibility coordinates backed by xxh128, not
authentication. They prove that the runtime configuration matches the build
input; they do not make an untrusted PHP artifact safe to execute. Array
definitions that require dynamic class or method construction are deliberately
omitted and reported as skipped.

When the artifact is loaded before the first service resolution, InterMix uses
a compiled-first resolver that does not construct the class, parameter, or
property reflection graph. If an omitted definition later needs dynamic
autowiring, that graph is initialized once as the fallback. Containers without
a compiled map retain their ordinary dynamic resolver path with no compiled
lookup. Cache generation also fuses service IDs that share an identical safe
expression into one dispatcher arm, reducing generated code and cold-load work.

Immutable Deployment Fast Path
------------------------------

``useCompiled()`` remains the safe standalone default and recomputes the full
container identity before activating an artifact. Frameworks that validate an
immutable release during deployment can avoid repeating that work in every
short-lived request. Publish ``compilationReport()['fingerprint']`` in the same
atomic deployment manifest as the artifact, then load both values together:

.. code-block:: php

   $container->usePrevalidated(
       __DIR__ . '/bootstrap/cache/container.php',
       $optimizeManifest['container_fingerprint'],
   );

The xxh128 fingerprint is not a secret. It is an integrity coordinate tying two
trusted deployment outputs together. Never obtain it or the artifact path from
request input. Rebuild and republish both after definitions, environment,
container options, PHP, InterMix, providers, or deployment configuration
changes. Applications without an atomic immutable deployment should continue
using ``useCompiled()``.

Artifact activation is not automatically faster for every request. Loading a
generated dispatcher without an opcode cache has a fixed request cost, while
direct compiled transient resolution saves work after activation. Frameworks
should activate the prevalidated path only when the immutable artifact is
available to OPcache or preload. If that deployment capability is unavailable,
retain the ordinary dynamic resolver instead of guessing how many services a
request might resolve. Persistent workers may load the artifact once at worker
boot.

Choose this capability once from deployment configuration or optimized cache
metadata; do not inspect the dependency graph or call ``opcache_get_status()``
for each resolution. Remember that newly written files may be excluded briefly
by ``opcache.file_update_protection``. Publish caches before switching traffic,
warm or preload them where supported, and benchmark the actual deployment SAPI.

Runtime Mutation
----------------

Definition, context, environment, attribute, resolver, or option mutations
invalidate every active compiled resolver. Dynamic resolution continues to
work, but the map must be regenerated and loaded again to restore compiled
resolution. For production, finish registration, load the artifact, and then
lock the container.

Compilation Report
------------------

``compilationReport()`` returns ``null`` before the first successful build.
After compilation it returns the artifact path and fingerprint, the compiled
service IDs, and a reason for each skipped definition. Skipping is intentional:
unsupported definitions retain normal dynamic behavior rather than being
serialized or approximated.
