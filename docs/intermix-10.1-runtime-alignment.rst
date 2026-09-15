:orphan:

.. _intermix.10.1.runtime-alignment:

=====================================
InterMix 10.1 Runtime Alignment Notes
=====================================

InterMix 10.1 hardens scoped dependency injection for persistent and structured
execution while keeping the production API framework-neutral. Existing
sequential applications continue to use ``enterScope()``, ``leaveScope()`` and
``withinScope()`` exactly as before.

What changed
------------

* Physical execution-carrier identity is separated from logical DI-scope
  identity.
* Independent PHP Fibers and Swoole/OpenSwoole coroutines remain isolated by
  default.
* ``captureScopeContext()`` and ``withinScopeContext()`` provide explicit,
  process-local logical-scope propagation to structured child work.
* Attached children share the owner's scoped instances and seeds while keeping
  their own nested active-scope position.
* Live attachment leases prevent an owner scope from closing underneath child
  work.
* Concurrent cold construction of the same scoped service in one logical scope
  is rejected deterministically instead of materializing duplicate instances.
* ``resetCurrentExecutionScope()`` provides idempotent, carrier-local cleanup
  for framework/runtime ``finally`` blocks.
* Generated ``ProductionContainer`` runtimes implement the same semantics,
  including dynamic fallback/runtime-island synchronization and explicit
  deoptimization.
* Fiber carrier tokens are weakly keyed rather than based on reusable
  ``spl_object_id()`` values. Swoole/OpenSwoole prefer weak object-backed
  coroutine contexts when available.
* Process-wide mutable caches/registries are classified for persistent-worker
  safety.

Compatibility
-------------

InterMix 10.1 does **not** make Runwire, Swoole, OpenSwoole, PCNTL or POSIX a
production requirement. ``infocyph/runwire`` is used only by development and
integration coverage. Plain PHP, PHP-FPM, Apache/CGI/FastCGI, LiteSpeed and
shared hosting remain supported first-class execution models.

The ordinary sequential scope path remains the compatibility/performance
baseline. Explicit propagation is opt-in; existing Fibers/coroutines do not
start sharing scoped services merely by upgrading.

Framework integration
---------------------

A framework/runtime should own semantic request/job boundaries and use only the
small InterMix surface below:

.. code-block:: php

   $container->enterScope('request', $seeds);

   try {
       $scopeContext = $container->captureScopeContext();

       // Propagate $scopeContext through the runtime's task-local mechanism.
       // Each structured child executes:
       $container->withinScopeContext($scopeContext, $childCallback);
   } finally {
       $container->leaveScope();
       $container->resetCurrentExecutionScope();
   }

Schedulers remain responsible for task creation, join, cancellation and
request deadlines. InterMix remains responsible for DI scope identity, scoped
service lifetime, scope attachment and cleanup.

Migration guidance
------------------

Sequential applications
~~~~~~~~~~~~~~~~~~~~~~~

No migration is required. Continue using ``withinScope()`` or balanced
``enterScope()`` / ``leaveScope()`` calls.

Persistent workers
~~~~~~~~~~~~~~~~~~

Use one owner scope per request/job and always close it in ``finally``. Add
``resetCurrentExecutionScope()`` at the outer framework boundary as defensive
cleanup. Do not derive container aliases or process-global registrations from
request/job identifiers.

Structured child work
~~~~~~~~~~~~~~~~~~~~~

Do not pass scoped service objects into children merely to preserve identity.
Capture the opaque ``ScopeContext`` once, propagate that handle through the
runtime's task-local facility, and wrap each child callback with
``withinScopeContext()``.

Do not serialize ``ScopeContext`` or propagate it across processes. A process
worker must create/own its own container and logical scopes.

Configuration mutation
~~~~~~~~~~~~~~~~~~~~~~

Do not mutate definitions, change environments, replace production fallbacks or
deoptimize while another execution carrier is attached to an active logical
scope. InterMix rejects unsafe concurrent graph transitions with
``ContainerException``. Single-carrier deoptimization remains supported.

Failure behavior worth noting
-----------------------------

* Closing an owner scope with live child attachments throws.
* Attaching a foreign or stale ``ScopeContext`` throws.
* Attaching to a carrier that already has an active scope throws.
* A second carrier attempting the same unresolved scoped construction while the
  first carrier is still constructing it throws.
* ``withinScopeContext()`` unwinds child-owned nested frames and detaches in
  ``finally`` when the callback throws or is cancelled by its runtime.

Validation in 10.1
------------------

The release branch exercises:

* dynamic, compiled, runtime-island and deoptimized semantic parity;
* repeated persistent request/job scope churn;
* stabilized post-warmup process memory and complete release of dynamic
  execution-store/carrier/logical-scope bookkeeping;
* stable application-container alias cardinality across repeated framework-style
  reuse;
* Fiber failure cleanup and carrier-token reuse protection;
* released Runwire 1.0 task-local propagation, fail-fast cancellation, explicit
  task cancellation and deadline expiry;
* optional Swoole/OpenSwoole coroutine carrier compatibility on PHP 8.4/8.5;
* dedicated structured-scope microbenchmarks alongside the existing sequential
  request/production benchmarks; and
* same-runner InterMix 10.0.4-to-10.1 regression gates on PHP 8.4/8.5 for the
  generated sequential production path and isolated-Fiber scope path.

The persistent-churn stress test warms the runtime, measures four additional
churn windows, requires both memory growth and sample spread to stay within
1 MiB, requires the dynamic execution scope store to return to ``null`` after
each window, and verifies captured/logical scope objects are collectible through
``WeakReference``. A separate framework-style test reuses one stable container
alias for 256 request scopes and verifies the process alias registry returns to
its exact pre-test cardinality after cleanup.

The final release-regression comparison against 10.0.4 remained inside the
frozen budgets:

* PHP 8.4: sequential production -0.46%; isolated Fiber +3.19%;
* PHP 8.5: sequential production +2.65%; isolated Fiber +2.13%.

The enforced limits are 3% for the ordinary generated production path and 5%
for isolated-Fiber scope round trips. See :doc:`benchmark` for the measurement
method and interpretation.

See :doc:`di/scopes` for the API contract and :doc:`benchmark` for the
performance review policy.
