.. _di.lazy_loading:

================
Lazy loading
================

Lazy class construction can be configured before finalization.

.. code-block:: php

   $builder->enableLazyLoading(true);

The setting belongs to the builder and is frozen with the graph. Runtime factory closures execute only when their definition is resolved according to its lifetime.

Use explicit factories for runtime behavior and FactoryDefinition for deterministic compilation-safe construction.
