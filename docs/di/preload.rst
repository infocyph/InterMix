.. _di.preload:

================================================
Class preload generation
================================================

ContainerBuilder can generate a preload file from finalized configuration metadata.

.. code-block:: php

   $builder = ContainerBuilder::create('app')
       ->autowire(Logger::class, JsonLogger::class)
       ->autowire(Service::class, Service::class);

   $builder->generatePreload(__DIR__ . '/preload.php');

Configure the generated file through PHP's opcache.preload setting at process startup.

Preload generation finalizes the builder. Create a new builder if wiring must change afterward. Treat the generated preload file as a deployment artifact and regenerate it with the matching application release.
