.. _di.best_practices:

================================================
Best practices
================================================

* Keep all DI configuration in ContainerBuilder during bootstrap.
* Prefer explicit value(), autowire(), factory(), alias(), and input() definitions.
* Use scoped lifetime for request/job state and declare host-supplied state with input().
* Do not let singleton definitions capture scoped/request values.
* Finalize once, then pass RuntimeContainerInterface through the host/application boundary.
* Use withinScope() for owned work and withinScopeContext() only for borrowed child work.
* Keep optional diagnostics disabled on ordinary hot paths.
* Use compile() and production() for generated production runtimes; protect generated PHP and metadata as trusted release artifacts.
* Use productionPrevalidated() only when the artifact digest comes from trusted deployment metadata.
* Configure external definition caching explicitly with a PSR-6 pool, namespace, generation, and per-definition eligibility.
* Reconfigure by creating a new builder/runtime and switching at a safe host lifecycle boundary.
* Keep Runwire/CacheLayer lifecycle ownership in the host; InterMix only propagates the exact supplied context objects.
* Measure representative host RPM/RPS in addition to component microbenchmarks.

See also :ref:`di.development-production`, :ref:`di.lifetimes`, :ref:`di.cache`,
:ref:`di.compiled-resolvers`, and :ref:`di.preload`.
