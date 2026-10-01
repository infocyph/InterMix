.. _di.options:

================
Builder options
================

InterMix 11 configuration options are explicit ContainerBuilder methods.

.. code-block:: php

   $builder
       ->setEnvironment('prod')
       ->enableLazyLoading(true)
       ->enablePropertyAttributes(true)
       ->enableMethodAttributes(true)
       ->enableDebugTracing(false);

Attribute resolvers are registered with registerAttributeResolver(). Lifecycle hooks use onResolving(), onResolved(), and onScopeLeave().

Definition cache policy is configured with definitionCache() and cacheDefinition().

Options become immutable with the rest of the graph after successful finalization.
