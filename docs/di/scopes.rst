.. _di.scopes:

========
Scopes
========

A **scope** groups services registered with ``LifetimeEnum::Scoped``. Inside one
logical scope, a scoped service behaves like a singleton. A new logical scope
gets a fresh instance.

Typical boundaries are an HTTP request, queue job, CLI command, tenant unit of
work, or another application-owned execution boundary.

InterMix 10.1 distinguishes two concepts that are deliberately independent:

* the **physical execution carrier** — the root PHP execution, a PHP ``Fiber``,
  or a Swoole/OpenSwoole coroutine; and
* the **logical DI scope** — the request/job/command lifetime that owns scoped
  service identity and scope seeds.

Independent Fibers/coroutines are isolated by default. InterMix never assumes
that child work should inherit a parent's logical DI scope. Structured child
work shares a logical scope only when the application explicitly captures and
attaches an opaque ``ScopeContext``.

Basic API
---------

.. code-block:: php

   $container->enterScope('request', [Request::class => $request]);

   try {
       $service = $container->get(RequestService::class);
   } finally {
       $container->leaveScope();
   }

``withinScope()`` is the preferred shorthand when execution is sequential:

.. code-block:: php

   $response = $container->withinScope(
       'request',
       static fn (Container $active) => $active->call($handler),
       [Request::class => $request],
   );

The third argument to ``withinScope()``—and the second argument to
``enterScope()``—is an ``ID => value`` seed map. Seeds:

* take precedence only while their scope is active;
* participate in ``get()`` and type-based injection;
* may contain ``null`` values;
* do not mutate container definitions; and
* are released when the owning scope closes.

Explicit structured propagation
-------------------------------

When child work must participate in the same request/job scope, capture the
logical scope and attach it around the child callback:

.. code-block:: php

   $container->enterScope('request', [Request::class => $request]);

   try {
       $context = $container->captureScopeContext();
       $parent = $container->get(RequestService::class);

       $fiber = new Fiber(static fn () => $container->withinScopeContext(
           $context,
           static fn (Container $active) => $active->get(RequestService::class),
       ));

       $fiber->start();
       $child = $fiber->getReturn();

       assert($child === $parent);
   } finally {
       $container->leaveScope();
       $container->resetCurrentExecutionScope();
   }

``ScopeContext`` is intentionally opaque. It is:

* container-bound;
* process-local;
* non-serializable;
* valid only while its owning logical scope remains open; and
* explicit — merely creating a Fiber/coroutine never propagates it.

Do not inspect it, persist it, copy it between processes, or turn it into an
application request identifier.

Nested child scopes
-------------------

An attached child may enter a nested scope. That nested active position belongs
to the child carrier only; siblings continue to see the shared parent scope.

.. code-block:: php

   $container->withinScopeContext($context, static function (Container $active) {
       $requestService = $active->get(RequestService::class);

       $active->withinScope('nested-operation', static function (Container $nested) use ($requestService) {
           $nestedService = $nested->get(RequestService::class);
           assert($nestedService !== $requestService);
       });

       assert($active->get(RequestService::class) === $requestService);
   });

Two siblings that enter the same nested scope name still receive independent
nested scoped instances. Returning from the nested scope restores each child to
the shared owning request/job scope.

Ownership and cleanup
---------------------

The carrier that creates a logical scope owns it. Attached children hold
leases on that scope. The owner may not silently close/reset the scope while a
child is still attached; InterMix throws ``ContainerException`` instead of
allowing use-after-close or partial cleanup.

``withinScopeContext()`` attaches and detaches in ``finally``. It also unwinds
carrier-local nested scopes before releasing the attachment. Scope-leave hooks
therefore run for child-owned nested frames, but attaching/detaching the shared
parent does **not** fire the parent's leave hook. The owning carrier fires that
hook exactly once when it closes the scope.

Frameworks and persistent workers should use
``resetCurrentExecutionScope()`` as a defense-in-depth cleanup primitive in an
outer ``finally`` block. It is idempotent and affects only the current carrier:

.. code-block:: php

   try {
       return $container->withinScope('request', $dispatch, $seeds);
   } finally {
       $container->resetCurrentExecutionScope();
   }

On an attached carrier, reset closes only carrier-local nested frames and then
releases the attachment; it does not close the shared owner scope.

Concurrent scoped construction
------------------------------

One logical scope must never materialize two instances of the same scoped
service merely because two attached children interleave during first
construction. InterMix tracks in-flight scoped construction on the logical
scope. If another carrier attempts the same cold scoped resolution before the
first construction completes, it fails deterministically with
``ContainerException``. Once an instance is resolved, ordinary reads remain the
fast path.

This is a collision guard, not a scheduler or lock primitive. If application
construction intentionally suspends and competing children must wait rather
than fail, coordinate that work at the runtime/application layer.

Production and deoptimization parity
------------------------------------

Generated ``ProductionContainer`` runtimes implement the same logical-scope
contract as the dynamic container, including:

* capture/attach/detach;
* carrier-local nested frames;
* propagated seeds and scoped identity;
* attachment liveness checks;
* concurrent cold-construction protection;
* current-carrier reset; and
* dynamic fallback/runtime-island synchronization.

Explicit deoptimization preserves already-materialized singleton/scoped
identity and the active logical scope. Generated production artifacts do not
import Runwire, Swoole, OpenSwoole, or framework types.

Runwire and other runtimes
--------------------------

InterMix owns DI scope identity; a scheduler owns task scheduling,
cancellation, deadlines and task-local propagation. Keep that boundary small:

1. enter the semantic InterMix request/job scope;
2. capture ``ScopeContext``;
3. propagate that opaque value through the runtime's task-local mechanism;
4. wrap each structured child with ``withinScopeContext()``;
5. join/cancel children at the runtime layer;
6. leave the owner scope in ``finally``; and
7. call ``resetCurrentExecutionScope()`` as defense in depth.

``infocyph/runwire`` is not a production dependency of InterMix. InterMix also
does not require PCNTL, POSIX, Swoole or OpenSwoole. Plain PHP, PHP-FPM and
shared-hosting execution remain first-class.

Swoole/OpenSwoole carrier detection is optional. When available, InterMix uses
an object-backed coroutine context as a weakly-keyed physical carrier token;
where a runtime exposes only a numeric coroutine ID, InterMix falls back to
that ID and relies on deterministic scope cleanup. A currently running PHP
``Fiber`` takes precedence over its host coroutine as the physical carrier.

Mutation safety
---------------

Container graph/configuration mutation is process-wide, unlike scoped
resolution. While concurrent/shared scope activity is live, operations that
would invalidate global resolution state—such as definition/environment
mutation, fallback replacement, or production deoptimization—are rejected.

The established single-carrier deoptimization path remains supported; the guard
only prevents mutation when another carrier/attachment could observe a partial
transition.

Persistent-worker rules
-----------------------

* Use stable application/container aliases. Never derive aliases from request,
  tenant, principal or job IDs.
* Open one semantic scope per request/job and close it in ``finally``.
* Do not retain scoped instances or ``ScopeContext`` handles after the owner
  scope closes.
* Propagate logical scope context explicitly only to structured child work.
* Use ``resetCurrentExecutionScope()`` at framework/runtime boundaries.
* Treat shared singleton services as application-lifetime objects and make them
  concurrency-safe when used by concurrent carriers.
* Register macros, reflection/configuration helpers and other process-wide
  configuration during bootstrap, not per request.

InterMix's process-wide state classification is recorded in
``docs/plans/intermix-10.1-process-state-audit.md``.

Related pages
-------------

* :doc:`lifetimes` – Scoped vs Singleton and Transient lifetimes.
* :doc:`lazy_loading` – defer heavy work until a scoped service is used.
* :doc:`development-production` – generated runtime and fallback behavior.
