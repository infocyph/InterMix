.. _benchmark:

=====================
Benchmarking InterMix
=====================

InterMix ships with PhpBench suites at:

- ``benchmarks/IntermixBench.php``
- ``benchmarks/RuntimeFeaturesBench.php``
- ``benchmarks/CompiledResolverBench.php``
- ``benchmarks/FenceBench.php``

Run via Composer:

.. code-block:: bash

   composer ic:benchmark

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
- Serialized Closure invocation as a cold fallback
- Unsigned and signed Closure serialization/deserialization
- MacroMix instance/static invocation
- MacroMix direct and bulk registration with mutation locking on and off
- Compiled artifact generation, boot, prevalidated boot, and resolution
- Definition-free scope seeding for ready request/job instances
- Service-provider registration path
- Environment-conditional interface binding path
- Manual object graph baseline (non-container)

Output columns
--------------

- ``benchmark``: benchmark class name
- ``subject``: measured scenario method
- ``revs``: revolutions per iteration
- ``its``: number of iterations
- ``mem_peak``: peak memory in the measured process
- ``mode``: modal execution time for the subject
- ``rstdev``: relative standard deviation
