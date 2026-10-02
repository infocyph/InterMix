.. _di.overview:

================
DI overview
================

InterMix 11 separates graph configuration from graph execution.

ContainerBuilder
----------------

ContainerBuilder owns definitions, environment selection, attributes, hooks, definition-cache policy, diagnostics, and compilation.

.. code-block:: php

   $builder = ContainerBuilder::create('app')
       ->value('app.name', 'demo')
       ->autowire(Logger::class, JsonLogger::class)
       ->autowire(Service::class, Service::class);

   $runtime = $builder->build();

The first successful build(), compile(), production(), or productionPrevalidated() finalizes the graph. Later mutation attempts fail before changing state.

RuntimeContainerInterface
-------------------------

Container and ProductionContainer share the runtime contract:

* get()/has() for PSR-11 access.
* make() for fresh class construction.
* invoke() for callable execution.
* tagged() for tagged definitions.
* withinScope() for owned request/job scopes.
* captureScopeContext()/withinScopeContext() for borrowed child work.
* resetCurrentExecutionScope() for explicit host cleanup.

No public runtime configuration manager exists in 11.0.

Dynamic and production runtimes
-------------------------------

build() returns a dynamic Container from the same finalized DefinitionGraph used by compile(). production() loads a compiled artifact against that frozen graph. Each runtime owns separate singleton and scope stores.
