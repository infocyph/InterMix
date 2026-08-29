.. _di.best_practices:

===============
Best Practices
===============

Follow these principles to get the most out of InterMix:

* **Prefer interfaces** in constructors to support swappable implementations.
* Use a **single source of truth** – centralize container configuration in a dedicated bootstrap file.
* Choose **scoped lifetimes** for services that are request- or task-bound (e.g. in-memory caches).
* **Lock** the container after setup in production environments to prevent accidental rebinding.
* Prioritize a **compiled resolver with OPcache/preload** for object-heavy graphs;
  add definition caching when safe scalar/array singletons benefit from cross-container reuse.
* For InterMix 10 deployments, configure through ``ContainerBuilder`` and run
  ``validate()`` plus ``compile()`` outside live requests.
* Treat the generated PHP file and its ``.meta.json`` manifest as one immutable
  release artifact. Use ``productionPrevalidated()`` only with a digest read
  from trusted deployment metadata.
* Set the environment before compilation. Loading rejects an artifact compiled
  for a different environment.
* Recompile after any builder, manager, or development-container configuration
  mutation. An active generated runtime deoptimizes safely; the stale artifact
  cannot be loaded again from that builder.
* Use a separate builder/fallback graph for production runtimes that must remain
  active concurrently.

See also: :ref:`di.development_production`, :ref:`di.lifetimes`, :ref:`di.cache`, :ref:`di.compiled_resolvers`, :ref:`di.preload`
