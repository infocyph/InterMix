.. _di.understanding:

========================
How the runtime fits
========================

InterMix is an embedded library. It does not become a server, worker supervisor, scheduler, event loop, or framework.

The host owns:

* process and worker lifecycle,
* request/job boundaries,
* optional Runwire runtime/request/coroutine objects,
* cache pools and external services,
* deployment cutover between finalized runtimes.

InterMix owns:

* a finalized definition graph,
* per-runtime singleton state,
* per-scope scoped state,
* construction/invocation resolution,
* generated artifact validation,
* bounded diagnostic/cache-admission bookkeeping.

Keep request-specific values inside declared scoped inputs and scoped services. Do not capture request state in singleton definitions or long-lived caller-owned objects.
