.. _di.options:

================
Builder options
================

InterMix 11 configuration options are explicit ContainerBuilder methods.

.. list-table::
   :header-rows: 1

   * - Method
     - Default
     - Effect
   * - enableLazyLoading(bool)
     - true
     - Defer class binding resolution until use; this does not create PHP lazy-object proxies.
   * - enablePropertyAttributes(bool)
     - false
     - Process property injection attributes.
   * - enableMethodAttributes(bool)
     - false
     - Process method/parameter injection attributes.
   * - enableDebugTracing(bool, TraceLevelEnum)
     - false; Node when enabled
     - Collect optional dynamic Container resolution diagnostics. Disabling selects Off.
   * - enableAutoBind(bool)
     - false
     - Convert incompatible single-class parameter values through fromBoundValue.

All enable methods accept a boolean; omitting it enables the option, and passing
``false`` disables it. Configure them before finalization. Defaults apply to both
dynamic and compiled runtimes; see the linked guides for each option's boundaries.

.. code-block:: php

   $builder
       ->setEnvironment('prod')
       ->enableLazyLoading(true)
       ->enablePropertyAttributes(true)
       ->enableMethodAttributes(true)
       ->enableDebugTracing(false)
       ->enableAutoBind();

Attribute resolvers are registered with registerAttributeResolver(). Lifecycle hooks use onResolving(), onResolved(), and onScopeLeave().

Tracing levels are ``Off``, ``Error``, ``Warn``, ``Info``, ``Node`` and ``Verbose``.
See :ref:`di.debug-tracing`, :ref:`di.attribute` and :ref:`di.lazy_loading` for
diagnostic, attribute and lazy-binding behavior.

Definition cache policy is configured with definitionCache() and cacheDefinition().

Options become immutable with the rest of the graph after successful finalization.

``enableAutoBind()`` opts into class-defined conversion for incompatible parameter
values. It is disabled by default; pass ``false`` to disable it before finalization.
See :ref:`di.invocation` for the conversion contract. Compiled artifacts retain the
selected policy, including when loaded without the original builder.
