.. _di.attribute:

================
Attribute injection
================

Property and method attribute processing is enabled on ContainerBuilder.

.. code-block:: php

   $builder
       ->enablePropertyAttributes(true)
       ->enableMethodAttributes(true)
       ->registerAttributeResolver(CustomAttribute::class, CustomResolver::class);

Attribute resolver callbacks receive the runtime contract, not a mutable builder.

Explicit autowire arguments/properties and explicit invoke() arguments remain the clearest way to provide scalar or request-specific values.

Attribute resolver registration is part of the finalized graph identity used by compiled artifacts.
