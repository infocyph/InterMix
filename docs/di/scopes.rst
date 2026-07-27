.. _di.scopes:

========
Scopes
========

A **scope** is a *label* that groups together all services registered with the
``LifetimeEnum::Scoped`` lifetime.
Inside the same scope, a scoped service behaves like a singleton; change the
label and you get a fresh instance.

Typical use-cases
-----------------

* **HTTP request** ID – isolate per-request state or caches.
* **CLI job** / **queue worker** – reuse expensive objects during the job but
  not across jobs.
* **Fiber / coroutine** – give each fiber its own contextual dependencies.
* **Multi-tenant apps** – tag each tenant with their customer ID.

API
---

.. code-block:: php

   $c->enterScope('req-123');     // ① enter / switch scope
   // ... resolve scoped services ...
   $c->leaveScope();              // ② leave and restore previous scope

Switching scope *never* clears non-scoped singletons; only services bound with
``LifetimeEnum::Scoped`` are affected.

Example 🍰
---------

.. code-block:: php

   use Infocyph\InterMix\DI\Support\LifetimeEnum;

   $def->bind('user.ctx', fn () => new StdClass, LifetimeEnum::Scoped);

   // ── Request #1 ────────────────────────────────
   $c->enterScope('req-A');
   $a1 = $c->get('user.ctx');     // instance #1
   $a2 = $c->get('user.ctx');     // same object (cached)
   $c->leaveScope();

   // ── Request #2 ────────────────────────────────
   $c->enterScope('req-B');
   $b1 = $c->get('user.ctx');     // new instance (instance #2)
   $c->leaveScope();

   assert($a1 !== $b1);

Scope helpers
-------------

If you need a *temporary* scope:

.. code-block:: php

   $c->withinScope('cli-batch-42', function () use ($c) {
       $svc = $c->get('user.ctx');   // scoped inside the closure
   });
   // scope automatically restored

( ``withinScope`` enters the scope, runs your callback, then always restores. )

Seed ready instances
--------------------

Request and worker runtimes can expose already-created contextual objects
without registering or rewriting definitions:

.. code-block:: php

   $response = $c->withinScope(
       'request-42',
       function (Container $scoped) {
           return $scoped->call(
               static fn (Request $request) => handle($request),
           );
       },
       [Request::class => $request],
   );

The third argument to ``withinScope()``—and the second argument to
``enterScope()``—is an ``ID => instance`` map. Seeds:

* take precedence over global definitions only while their scope is active;
* participate in ``get()`` and type-based parameter injection;
* may contain ``null`` values;
* are removed automatically by ``leaveScope()``; and
* do not modify definition metadata or lifetime caches.

Best practices 💡
----------------

* **Keep scopes short-lived** – usually the lifetime of a single request or job.
* **Avoid cross-scope leakage** – pass *IDs* or *DTOs* between scopes, not the
  scoped objects themselves.
* **Combine with Lazy-Loading** – scoped services are still initialised on first
  access unless eager-loaded.
* **Seed runtime context** – pass request/job objects as scope seeds instead of
  rebinding their definitions for every operation.

Related pages
-------------

* :doc:`lifetimes` – how Scoped compares to Singleton & Transient.
* :doc:`lazy_loading` – defer heavy work until the scoped service is used.
