.. _benchmark:

=====================
Benchmarking InterMix
=====================

InterMix ships with PhpBench suites at:

- ``benchmarks/IntermixBench.php``
- ``benchmarks/RuntimeFeaturesBench.php``
- ``benchmarks/CompiledResolverBench.php``
- ``benchmarks/CompiledRuntimeBench.php``
- ``benchmarks/DefinitionCacheBench.php``
- ``benchmarks/RequestPathBench.php``
- ``benchmarks/FenceBench.php``

Run via Composer:

.. code-block:: bash

   composer ic:benchmark

Run the focused InterMix 10 production-runtime matrix with:

.. code-block:: bash

   composer bench:intermix

Other useful variants:

.. code-block:: bash

   composer ic:bench:quick
   composer ic:bench:chart

What it measures
----------------

The suite covers DI paths end-to-end:

- Singleton ``get()`` hot-path throughput
- Scoped ``get()`` and ``has()`` hot paths
- Transient object graph creation via ``make()``
- Closure invocation through container DI
- Reflected and direct transient factory resolution
- Class-method invocation via ``registerMethod()`` + ``make(..., method)``
- Property wiring via ``registerProperty()`` + ``make()``
- Immediate resolution via ``resolveNow()`` (class and method paths)
- Scoped lifetime behavior with ``enterScope()`` / ``leaveScope()``
- Tagged service lookup via ``findByTag()``
- Lazy tagged iteration via ``tagged()``
- ``Invoker`` wrapper method invocation path
- ``Invoker`` static-method callable fast path
- ``Invoker`` zero-argument closure fast path
- ``Invoker`` function, invokable object, class-string, and static-method string paths
- Unsigned and signed Closure serialization/deserialization
- MacroMix instance/static invocation
- MacroMix direct and bulk registration with mutation locking on and off
- Compiled artifact generation, boot, prevalidated boot, and resolution
- Generated ``ProductionContainer`` singleton/transient graphs versus dynamic,
  compatible compiled-resolver, array-map, and native-construction baselines
- Uncached scalar resolution and PSR-6/CacheLayer memory-cache hits
- CacheLayer APCu hits when APCu is available
- Definition-cache warmup at 10, 100, and 1000 entries
- Bulk versus sequential warmup and logical generation rotation
- Definition-free scope seeding for ready request/job instances
- Service-provider registration path
- Environment-conditional interface binding path
- Manual object graph baseline (non-container)

Interpretation
--------------

These are isolated microbenchmarks, not application requests-per-second or
capacity guarantees. Compare the same subject, PHP build, extensions, machine,
warmup and revision; inspect variance before acting on a difference. Generated
runtime improvements should also be validated in an application-shaped request
or job benchmark.

Output columns
--------------

- ``benchmark``: benchmark class name
- ``subject``: measured scenario method
- ``revs``: revolutions per iteration
- ``its``: number of iterations
- ``mem_peak``: peak memory in the measured process
- ``mode``: modal execution time for the subject
- ``rstdev``: relative standard deviation
