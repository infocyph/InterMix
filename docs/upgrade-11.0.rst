.. _upgrade-11.0:

=================================
Upgrading from InterMix 10.1
=================================

InterMix 11 is a deliberate major-version redesign. Configuration moves to ContainerBuilder and runtime execution becomes immutable.

Core migration table
--------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - 10.1 pattern
     - 11.0 replacement
   * - Mutable Container registration
     - Configure ContainerBuilder, then call build().
   * - definitions()/registration()/options()
     - Direct builder methods such as value(), autowire(), factory(), input(), setEnvironment(), and configuration-specific enable methods.
   * - bind(mixed)
     - Choose value(), autowire(), factory(), alias(), or input() explicitly.
   * - singleton()/scoped()/transient()
     - Pass LifetimeEnum to autowire() or factory().
   * - Container::call() and getReturn()
     - Runtime invoke() with an explicit callable.
   * - String callable descriptors
     - Pass a real PHP callable, for example [$object, 'handle'].
   * - Manual enterScope()/leaveScope()
     - withinScope() and withinScopeContext().
   * - findByTag()
     - tagged().
   * - Container compilation/loading methods
     - ContainerBuilder::compile(), production(), and productionPrevalidated().
   * - Mutable production fallback/deoptimization
     - Build a new finalized graph and switch runtimes at a host lifecycle boundary.
   * - Process-global container lookup helpers
     - Pass RuntimeContainerInterface explicitly through the host/application boundary.
   * - Provider class discovery
     - Construct the provider in bootstrap and pass the instance to import().

Definitions
-----------

Before, one overloaded binding call inferred whether a value was a class, closure, scalar, or alias. InterMix 11 makes that choice explicit.

.. code-block:: php

   $builder
       ->value('app.name', 'demo')
       ->autowire(Logger::class, JsonLogger::class)
       ->factory('token', static fn () => bin2hex(random_bytes(16)))
       ->alias(Psr\Log\LoggerInterface::class, Logger::class)
       ->input(RequestContext::class);

Invocation
----------

Replace implicit class/method descriptors with real callables:

.. code-block:: php

   $handler = $runtime->make(Handler::class);
   $result = $runtime->invoke([$handler, 'handle'], ['payload' => $payload]);

Scopes
------

.. code-block:: php

   $result = $runtime->withinScope(
       'request-42',
       static fn ($active) => $active->get(RequestService::class),
       [RequestContext::class => $requestContext],
   );

Only declared scoped entries or input() declarations may be supplied as scope seeds.

Providers
---------

ServiceProviderInterface::register() receives ContainerBuilder.

.. code-block:: php

   final class AppProvider implements ServiceProviderInterface
   {
       public function register(ContainerBuilder $builder): void
       {
           $builder->autowire(Logger::class, JsonLogger::class);
       }
   }

   $builder->import(new AppProvider());

Compilation
-----------

.. code-block:: php

   $report = $builder->compile($path, strict: true);
   $runtime = $builder->production($path);

A finalized builder cannot be mutated. Artifact generation/loading failures do not reopen configuration.

Runwire and CacheLayer
----------------------

Runwire 2.1 and CacheLayer 4 are optional integrations. The host remains the lifecycle owner and supplies the exact runtime/request/coroutine objects. InterMix does not create workers, loops, request objects, or downstream runtime contexts on their behalf.
