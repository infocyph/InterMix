.. _di.cheat-sheet:

================
DI cheat sheet
================

Configuration
-------------

.. list-table::
   :header-rows: 1

   * - Operation
     - Purpose
   * - value(id, value)
     - Literal value.
   * - autowire(id, class, arguments, lifetime, tags, properties)
     - Class construction definition.
   * - factory(id, factory, lifetime, tags)
     - Runtime or declarative factory.
   * - alias(id, target)
     - Service alias.
   * - input(id)
     - Host-supplied scoped input.
   * - when(consumer)
     - Contextual binding builder.
   * - import(provider)
     - Import a provider instance.
   * - definitionCache(pool, namespace, generation)
     - Configure external definition caching.
   * - compile(path, strict)
     - Generate a frozen production artifact.

Runtime
-------

.. list-table::
   :header-rows: 1

   * - Operation
     - Purpose
   * - get()/has()
     - PSR-11 access.
   * - make()
     - Fresh class construction.
   * - invoke()
     - Execute a real callable with DI.
   * - tagged()
     - Iterate tagged services.
   * - withinScope()
     - Own a structured scope.
   * - captureScopeContext()/withinScopeContext()
     - Borrow an existing scope in child work.
   * - resetCurrentExecutionScope()
     - Explicit host recovery/reset boundary.

Lifetimes are selected with LifetimeEnum on autowire() and factory().
