.. _container:

===========================
Dependency injection runtime
===========================

InterMix 11 has two phases:

1. Configure a graph with ContainerBuilder.
2. Execute the finalized graph through Container or ProductionContainer.

Runtime objects do not expose public mutation managers.

.. toctree::
   :maxdepth: 2
   :caption: DI guide

   di/overview
   di/quickstart
   di/definitions
   di/registration
   di/lifetimes
   di/scopes
   di/invocation
   di/tagging
   di/environment
   di/options
   di/attribute
   di/lazy_loading
   di/cache
   di/compiled-resolvers
   di/development-production
   di/debug_tracing
   di/cheat_sheet
   di/understanding
   di/invoker

Minimal example
---------------

.. code-block:: php

   use Infocyph\InterMix\DI\ContainerBuilder;

   $builder = ContainerBuilder::create('app')
       ->value('answer', 42)
       ->autowire(Clock::class, SystemClock::class);

   $runtime = $builder->build();

   echo $runtime->get('answer');

Configuration is immutable after the first successful build, compile, or production-load finalization. Create a new builder when wiring changes.
