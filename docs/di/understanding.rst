.. _di.understanding:

==============================================
Understanding Dependency Injection & Container
==============================================

*(Conceptual chapter – the “why”.  Hands-on code lives in the other pages.)*

-------------------------------------------------
What *is* Dependency Injection (DI), really?
-------------------------------------------------

**DI** means a class *receives* (is **injected**) the things it needs to do its job
instead of **creating** them internally.
Less _new_ keywords → less coupling → easier change.

Without DI – brittle coupling
-----------------------------

.. code-block:: php

   class StoreService
   {
       public function getStoreCoordinates(Store $store): array
       {
           $geo = new GoogleMaps();          // 🔴 hard-wired
           return $geo->getCoordinatesFromAddress($store->address());
       }
   }

A future switch to *OpenStreetMap* touches **every line** that new’s `GoogleMaps`.

With DI – pluggable design
--------------------------

.. code-block:: php

   interface GeolocationService { public function locate(string $addr): array; }

   class GoogleMaps implements GeolocationService { /* … */ }
   class OpenStreetMap implements GeolocationService { /* … */ }

   class StoreService
   {
       public function __construct(private GeolocationService $geo) {}

       public function getStoreCoordinates(Store $store): array
       {
           return $this->geo->locate($store->address());
       }
   }

Business code knows only *the contract* (`GeolocationService`).
Swap concrete classes at runtime, in tests, or by environment – no edits in `StoreService`.

-------------------------------------------------------------
Why use a container instead of manual wiring?
-------------------------------------------------------------

* **Replaceability** – bind interface → class once, change it centrally.
* **Testability** – hand the container a fake/mock service in tests.
* **Single Source of Truth** – definitions live in one place; fewer bootstraps scattered around.
* **Optional power-ups** – autowiring, attributes, lazy loading, caching, scopes… when you need them.

InterMix in the picture
-----------------------

#. **Register** recipes (definitions, class bindings, factory closures).
#. **Resolve**: ``$c->get(StoreService::class)``.
#. InterMix analyses the constructor, asks itself *“What fulfills GeolocationService right now?”*,
   constructs the dependency (recursively if needed) **once**, and hands back a ready-to-use
   `StoreService`.

Environment-based overrides
---------------------------

One-liner switches for prod vs. local (or staging, CI, …):

.. code-block:: php

   $c->options()
      ->bindInterfaceForEnv('prod',  GeolocationService::class, GoogleMaps::class)
      ->bindInterfaceForEnv('local', GeolocationService::class, OpenStreetMap::class)
      ->setEnvironment($_ENV['APP_ENV'] ?? 'local');

Running in production will now build `GoogleMaps`; developers get `OpenStreetMap`
automatically.

-------------------------------------------------
Take-away
-------------------------------------------------

*DI gives you **loosely coupled** code;* the **container** automates the wiring so
you focus on behaviour, not plumbing.
Ready to see it in action? Jump to :ref:`di.quickstart` for practical recipes.
