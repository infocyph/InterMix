.. _benchmark:

=====================
Benchmarking InterMix
=====================

InterMix ships with a container benchmark script at:

- ``benchmark/intermix_benchmark.php``

Run via Composer:

.. code-block:: bash

   composer benchmark

What it measures
----------------

The benchmark compares:

- Singleton ``get()`` hot-path throughput
- Transient object graph creation via ``make()``
- Closure invocation through container DI
- Manual object graph baseline (non-container)

Progress output
---------------

Progress is reported as total percentage:

- ``[progress] 0%`` ... ``[progress] 100%``

Environment knobs
-----------------

Use environment variables to tune iteration counts:

.. code-block:: bash

   INTERMIX_BENCH_HOT_ITERATIONS=200000 INTERMIX_BENCH_COLD_ITERATIONS=25000 composer benchmark

- ``INTERMIX_BENCH_HOT_ITERATIONS`` defaults to ``200000``
- ``INTERMIX_BENCH_COLD_ITERATIONS`` defaults to ``25000``

Output columns
--------------

- ``iterations``: Number of loop iterations
- ``time (ms)``: Elapsed time per scenario
- ``ops/sec``: Throughput
- ``checksum``: Sanity signal to prevent dead-code elimination effects
