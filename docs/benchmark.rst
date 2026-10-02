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

benchmarks/HostAcceptance.php measures equivalent persistent-host request/job work using version-specific setup outside the measured loop. Dynamic mode is measured across the full concurrency curve, while generated production and hybrid-fallback modes are measured at the representative c32 point on PHP 8.4 and 8.5. Provider acceptance separately exercises the real Runwire 2.1 and CacheLayer 4 host boundaries.

It records:

* successful RPS and RPM,
* completed/failed responses,
* actual end-to-end per-request p50/p95/p99 latency,
* batch scheduling p50/p95/p99 as a separate diagnostic,
* elapsed time and process CPU utilization,
* steady and peak RSS,
* PHP memory usage and memory growth,
* bounded latency samples spanning the complete measured interval,
* maximum in-flight requests.

The workload is closed-loop and has no external request queue, so it reports that queue model explicitly instead of publishing a fabricated queue-depth value.

The release comparison uses seven alternating baseline/candidate process pairs plus two five-minute sustained baseline/candidate pairs run in opposite orders. Both throughput gates retain the 2% regression ceiling; the balanced long-run ordering reduces host-frequency and thermal drift without widening the budget. Tail-latency and resource ceilings use the worst observed five-minute baseline/candidate evidence. Response validation is mandatory; invalid or failed responses never count as successful throughput.

A component microbenchmark is diagnostic evidence, not proof of application-level performance.
