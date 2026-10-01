.. _benchmark:

=====================
Benchmarking InterMix
=====================

InterMix maintains component PhpBench suites plus release acceptance harnesses.

Component benchmarks
--------------------

Run the PHPForge quick benchmark profile:

.. code-block:: bash

   composer ic:bench:quick

The suites cover dynamic and compiled resolution, definition caching, request paths, structured scopes, runtime features, and Fence behavior.

Release regression
------------------

benchmarks/ReleaseRegression.php compares the immutable 10.1.1 baseline with the current candidate on PHP 8.4 and 8.5. It remains a mandatory diagnostic signal for hot-path regressions.

Representative host acceptance
------------------------------

benchmarks/HostAcceptance.php measures an equivalent persistent-host request workload using version-specific setup outside the measured loop. It records:

* successful RPS and RPM,
* completed/failed responses,
* p50/p95/p99 latency,
* elapsed time,
* CPU utilization,
* steady and peak RSS,
* PHP memory usage and memory growth.

The release comparison uses median sustained successful throughput across alternating baseline/candidate processes. Response validation is mandatory; invalid or failed responses never count as successful throughput.

A component microbenchmark is diagnostic evidence, not proof of application-level performance.
