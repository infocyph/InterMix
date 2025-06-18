.. _di.best_practices:

===============
Best Practices
===============

Follow these principles to get the most out of InterMix:

* **Prefer interfaces** in constructors to support swappable implementations.
* Use a **single source of truth** – centralize container configuration in a dedicated bootstrap file.
* Choose **scoped lifetimes** for services that are request- or task-bound (e.g. in-memory caches).
* **Lock** the container after setup in production environments to prevent accidental rebinding.
* Combine **definition caching** with **OPcache preload** for optimal performance and minimal runtime overhead.

See also: :ref:`di.lifetimes`, :ref:`di.cache`, :ref:`di.preload`
