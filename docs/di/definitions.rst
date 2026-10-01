.. _di.definitions:

================
Definitions
================

InterMix 11 uses explicit definition kinds on ContainerBuilder.

Values
------

value() returns the supplied value exactly. A Closure, callable-looking array, class-name string, false, and null remain literal values.

.. code-block:: php

   $builder
       ->value('name', 'InterMix')
       ->value('enabled', false)
       ->value('callback', static fn () => 'literal closure');

Autowired classes
-----------------

.. code-block:: php

   $builder->autowire(
       Logger::class,
       JsonLogger::class,
       arguments: ['channel' => 'api'],
       lifetime: LifetimeEnum::Singleton,
       tags: ['logging'],
       properties: ['enabled' => true],
   );

Factories
---------

Runtime closures and explicit FactoryDefinition recipes are separate factory forms:

.. code-block:: php

   $builder->factory(
       'token',
       static fn () => bin2hex(random_bytes(16)),
       lifetime: LifetimeEnum::Transient,
   );

Use FactoryDefinition for compilation-safe construction/static-factory recipes.

Aliases and inputs
------------------

.. code-block:: php

   $builder
       ->alias(Psr\Log\LoggerInterface::class, Logger::class)
       ->input(RequestContext::class);

input() declares a scoped value that the host supplies when entering a structured scope.

Validation
----------

validate() checks the mutable builder without finalizing it. A successful runtime finalization snapshots the graph and prevents later mutation.
