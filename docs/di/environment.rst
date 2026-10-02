.. _di.environment:

================================================
Environment configuration
================================================

Environment selection belongs to ContainerBuilder and happens before graph finalization.

.. code-block:: php

   $builder
       ->setEnvironment($_ENV['APP_ENV'] ?? 'prod')
       ->autowire(Logger::class, JsonLogger::class)
       ->bindInterfaceForEnv('test', Logger::class, InMemoryLogger::class)
       ->setDefinitionMetaForEnv(
           'test',
           Logger::class,
           tags: ['test-only'],
       );

The selected environment contributes to graph/artifact identity. Do not switch environments on a running runtime; create and install a new finalized runtime at a safe host lifecycle boundary.
