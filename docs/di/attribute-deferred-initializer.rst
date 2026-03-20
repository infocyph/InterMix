.. _di.attribute.deferred_initializer:
=========================================
DeferredInitializer (Lazy Wrapper)
=========================================

``DeferredInitializer`` is an internal lazy wrapper used by InterMix when
lazy-loading is enabled.

It is **not** a PHP attribute annotation. Instead, the container stores this
wrapper for eligible definitions and resolves the real service on first access.

**Usage**

.. code-block:: php

   use function Infocyph\InterMix\container;

   $c = container();
   $c->options()->enableLazyLoading(true);
   $c->definitions()->bind('expensive', ExpensiveService::class);

   // first get() triggers the deferred initializer internally
   $svc = $c->get('expensive');

**Behavior**

- The container may store a ``DeferredInitializer`` placeholder for class/array
  definitions while lazy loading is enabled.
- The first time the service is accessed, the real constructor/factory is executed.
- Subsequent accesses return the already-created instance
- This pattern is useful for services that are expensive to initialize but may not
  always be needed during a request

**Integration with Container**

The wrapper is created by the container's lazy loading mechanism:

.. code-block:: php

   $container = Container::instance('lazy-demo');
   $container->options()->enableLazyLoading(true);
   $container->definitions()->bind(LazyService::class, LazyService::class);

   $lazyService = $container->get(LazyService::class); // resolves on first access

**See Also**

- :doc:`attribute`
- :doc:`lazy_loading`
