.. _benchmark:

=====================
Benchmarking InterMix
=====================

InterMix ships with a phpbench benchmark suite at:

- ``benchmarks/IntermixBench.php``

Run via Composer:

.. code-block:: bash

   composer benchmark

Other useful variants:

.. code-block:: bash

   composer bench:quick
   composer bench:chart

What it measures
----------------

The suite covers DI paths end-to-end:

- Singleton ``get()`` hot-path throughput
- Transient object graph creation via ``make()``
- Closure invocation through container DI
- Class-method invocation via ``registerMethod()`` + ``make(..., method)``
- Property wiring via ``registerProperty()`` + ``make()``
- Immediate resolution via ``resolveNow()`` (class and method paths)
- Scoped lifetime behavior with ``enterScope()`` / ``leaveScope()``
- Tagged service lookup via ``findByTag()``
- ``Invoker`` wrapper method invocation path
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
