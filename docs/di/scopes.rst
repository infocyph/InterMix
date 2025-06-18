.. _di.scopes:

========
Scopes
========

A **scope** is a *label* that groups together all services registered with the
``Lifetime::Scoped`` lifetime.
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

   $repo = $c->getRepository();

   $repo->setScope('req-123');    // ① enter / switch scope
   $current = $repo->getScope();  // ② read current label
   $repo->resetScope();           // ③ back to root scope

Switching scope *never* clears non-scoped singletons; only services bound with
``Lifetime::Scoped`` are affected.

Example 🍰
---------

.. code-block:: php

   $def->bind('user.ctx', fn () => new StdClass, Lifetime::Scoped);

   // ── Request #1 ────────────────────────────────
   $repo->setScope('req-A');
   $a1 = $c->get('user.ctx');     // instance #1
   $a2 = $c->get('user.ctx');     // same object (cached)

   // ── Request #2 ────────────────────────────────
   $repo->setScope('req-B');
   $b1 = $c->get('user.ctx');     // new instance (instance #2)

   assert($a1 !== $b1);

Scope helpers
-------------

If you need a *temporary* scope:

.. code-block:: php

   $repo->withScope('cli-batch-42', function () use ($c) {
       $svc = $c->get('user.ctx');   // scoped inside the closure
   });
   // scope automatically restored

( ``withScope`` is a thin utility that saves → sets → restores the label. )

Best practices 💡
----------------

* **Keep scopes short-lived** – usually the lifetime of a single request or job.
* **Avoid cross-scope leakage** – pass *IDs* or *DTOs* between scopes, not the
  scoped objects themselves.
* **Combine with Lazy-Loading** – scoped services are still initialised on first
  access unless eager-loaded.

Related pages
-------------

* :doc:`lifetimes` – how Scoped compares to Singleton & Transient.
* :doc:`lazy_loading` – defer heavy work until the scoped service is used.
