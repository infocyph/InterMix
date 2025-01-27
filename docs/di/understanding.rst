.. _di.understanding:

==============================================
Understanding Dependency Injection & Container
==============================================

**Dependency Injection** means each class *receives* its dependencies externally
rather than creating them inside. A **container** automates that injection,
especially for large or complex setups.

-----------------
A Quick Analogy
-----------------

Without DI, your code is **tightly coupled**:

.. code-block:: php

   class StoreService
   {
       public function getStoreCoordinates($store)
       {
           $geo = new GoogleMaps(); // Hard-coded
           return $geo->getCoordinatesFromAddress($store->getAddress());
       }
   }

Switching to `OpenStreetMap` would require changing `StoreService`.

**With DI**:

.. code-block:: php

   interface GeolocationService { ... }

   class GoogleMaps implements GeolocationService { ... }
   class OpenStreetMap implements GeolocationService { ... }

   class StoreService
   {
       private GeolocationService $geo;
       public function __construct(GeolocationService $geo)
       {
           $this->geo = $geo;
       }
       // ...
   }

You decide which implementation to give `StoreService`. No code change in the
`StoreService` itself. That’s the fundamental “inversion of control.”

-----------------------------
Using a Container for DI
-----------------------------

A **container** like InterMix helps:

1. You **register** definitions or classes (e.g. `GoogleMaps`, `OpenStreetMap`).
2. You **ask** the container: ``$container->get(StoreService::class)``
3. The container sees that `StoreService` needs a `GeolocationService`, picks the right one,
   and returns a fully constructed `StoreService`.

With environment-based overrides, you can do:

.. code-block:: php

   $container->setEnvironment('production')
       ->options()
       ->bindInterfaceForEnv('production', GeolocationService::class, GoogleMaps::class)
       ->bindInterfaceForEnv('local', GeolocationService::class, OpenStreetMap::class);

So in “production,” it chooses `GoogleMaps`. In “local,” it chooses `OpenStreetMap`.

**Result**: Maximum flexibility, minimal duplication. See :ref:`di.usage` for
practical usage examples.
