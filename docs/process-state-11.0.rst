.. _process-state-11.0:

================================================
InterMix 11.0 process-state inventory
================================================

Runtime ownership
-----------------

InterMix 11 has no process-global container alias registry. Finalized Container and ProductionContainer instances are host-owned and keep singleton state per runtime. Scoped values, scope seeds, construction guards, attachments, and cleanup state live in per-runtime scope stores.

Execution carriers
------------------

Fiber carrier identity uses the live Fiber object ID without retaining a Fiber registry. Swoole/OpenSwoole object carrier identities use WeakMap and WeakReference ownership. The former strong last-Fiber fast path is absent.

Bounded metadata
----------------

Fence class/extension capability caches are bounded to 256 entries. Retained reflection and diagnostic metadata remain subject to explicit cache limits/reset contracts. Request, tenant, principal, return-value, and scope objects are not process-global metadata.

Optional providers
------------------

RunwireIntegration stores the host-supplied RuntimeContext binding plus its TaskLocal propagation key. The host owns request, coroutine, worker, event-loop, and cancellation lifecycles. Borrowed scope objects are attached/detached, not closed by InterMix.

Release evidence
----------------

The release candidate must retain terminated-Fiber/idle-worker collection coverage, dynamic/compiled scope churn, Swoole/OpenSwoole PHP 8.4/8.5 lanes, and a 30-minute persistent-host soak with controlled failure, cancellation, idle windows, zero wrong outputs, and bounded memory growth.
