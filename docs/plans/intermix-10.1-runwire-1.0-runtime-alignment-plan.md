# InterMix 10.1 — Runwire 1.0 Structured Runtime Alignment & Persistent-Execution Hardening Plan

## Status

Target release: **InterMix 10.1**

Branch: `intermix-10.1/runwire-1.0-runtime-alignment`

Baseline:

- InterMix **10.0** is the current published major release.
- `main` baseline at plan creation: `529081fea33fb5798f385fd9cbbed023865dba76`.
- PHP baseline remains `>=8.4`.
- PSR-11 / PSR-6 contracts remain unchanged.
- `infocyph/phpforge` remains `dev-main@dev`.
- Runwire **1.0** is now a released runtime substrate.

Primary downstream consumers of this work:

- Webrick runtime integration;
- Foundation 3 request/job/command execution scopes;
- Omnibus message execution;
- Infbyte persistent-runtime acceptance.

This is a **minor-release hardening/alignment pass**, not another InterMix architecture rewrite. InterMix 10's development/build/compiled-production model remains authoritative.

---

# 1. Release objective

InterMix 10.1 must make InterMix's scoped DI semantics correct and explicit across modern structured runtimes without turning InterMix into a runtime/server/concurrency framework.

The specific trigger is Runwire 1.0: Runwire can execute one logical request/job through a tree of structured child tasks, and those child tasks are separate PHP `Fiber` instances. InterMix 10.0 currently uses the physical Fiber/coroutine identity as the execution-context key for scoped state.

That is safe for independent concurrent Fibers, but it is not sufficient to model a logical request/job that intentionally spans multiple child tasks.

InterMix 10.1 therefore needs to distinguish:

```text
physical execution carrier
    PHP Fiber / Swoole coroutine / OpenSwoole coroutine / sequential PHP

from

logical DI scope ownership
    request / job / command / application-defined scope
```

The goal is not to make every child task share state automatically. The goal is to give frameworks an explicit, safe and runtime-neutral way to capture a logical scope and attach a child execution carrier to it when that is the intended semantic.

---

# 2. Non-negotiable portability invariant

InterMix must remain fully usable without Runwire.

The minimum supported runtime remains ordinary PHP execution:

```text
PHP CLI
Apache/mod_php
CGI
FastCGI
PHP-FPM
LiteSpeed
shared hosting
serverless/request-owned PHP
```

InterMix 10.1 must not require:

- Runwire;
- PCNTL;
- POSIX;
- a persistent worker;
- a long-running server;
- Swoole/OpenSwoole;
- Fibers being actively used;
- an event loop;
- coroutine scheduling;
- sockets;
- process supervision.

Advanced runtime capabilities are optional consumers of InterMix's generic scope contract. They must never become prerequisites for ordinary DI/container behavior.

---

# 3. Dependency policy

## 3.1 Production dependencies

Keep production dependencies unchanged unless an unrelated correctness issue forces otherwise:

```json
{
    "php": ">=8.4",
    "psr/cache": "^3.0",
    "psr/container": "^2.0"
}
```

**Do not add `infocyph/runwire` to `require`.**

InterMix must not expose Runwire classes in production public method signatures, generated production artifacts, container definitions, or compiled metadata.

## 3.2 Development/integration dependency

Runwire `^1.0` may be used as a development/integration-test dependency so the actual released coroutine/task-local behavior is exercised instead of approximated.

Runwire itself requires 64-bit PHP. That requirement must remain confined to development/integration testing and must not change InterMix's production platform contract.

If keeping the default InterMix development matrix installable on a non-64-bit PHP platform remains a project requirement, isolate the Runwire integration suite in a dedicated Composer fixture/profile instead of adding Runwire to the default `require-dev` set.

No Runwire-specific test should require PCNTL/POSIX. The InterMix integration suite needs only Runwire's structured coroutine/task-local layer.

---

# 4. Current InterMix 10.0 findings

## 4.1 Physical execution-context detection

`DI/Internal/ExecutionContext` currently resolves context identity in this order:

1. active PHP `Fiber` → `fiber:<spl_object_id>`;
2. active Swoole coroutine → `swoole:<cid>`;
3. active OpenSwoole coroutine → `openswoole:<cid>`;
4. otherwise no execution-context key and the sequential/root scope path is used.

This is useful isolation, but the identifier describes the **carrier**, not necessarily the logical request/job.

## 4.2 Dynamic container scoped state

`ConcurrentRepository` stores execution-local:

- active scope;
- scope stack;
- scope seeds;
- resolved scoped services;

through `ExecutionScopeStore`, keyed by `ExecutionContext::id()`.

Independent Fibers therefore receive isolated scoped instances even when they are children of one logical request.

## 4.3 Compiled production container scoped state

`ProductionContainer` follows the same physical-context model. Its `$executionScopes` map is also keyed by `ExecutionContext::id()` and stores `ScopeState` chains for compiled services.

Any change in 10.1 must preserve exact development/compiled/deoptimized parity.

## 4.4 Existing concurrency coverage

The current suite correctly proves:

- dynamic scoped isolation across interleaved Fibers;
- compiled scoped isolation across interleaved Fibers;
- seed isolation;
- sequential execution around Fiber execution;
- scope-leave hooks under sequential/Fiber usage.

That behavior must remain the default after 10.1.

## 4.5 Current documentation semantics

InterMix documents scopes as logical request/job scopes while also documenting automatic Fiber/Swoole execution-context isolation.

The missing distinction is between:

- an independent Fiber that should own a fresh logical scope; and
- a structured child Fiber that should intentionally participate in its parent's logical scope.

10.1 documentation must make that distinction explicit.

---

# 5. Runwire 1.0 facts relevant to InterMix

Runwire 1.0 now provides structured concurrency rather than merely process/network primitives.

Relevant released behavior includes:

- `CoroutineRuntime`;
- `CoroutineScope`;
- child `Task` objects backed by PHP Fibers;
- structured join/cancel semantics;
- request-owned coroutine execution through `runRequest()`;
- cancellation/deadline propagation;
- `TaskLocal` values;
- `TaskLocalInheritance::SNAPSHOT` child inheritance;
- automatic task-local cleanup when a task terminates.

A child task therefore receives a new PHP Fiber even when it is semantically part of the same parent request/job.

InterMix 10.0 sees that child as a completely separate scope carrier.

That mismatch is the main 10.1 problem to solve.

---

# 6. Architectural decision: separate carrier identity from logical scope identity

InterMix 10.1 should model two concepts.

## 6.1 Carrier identity

Carrier identity answers:

> "Which independently executing PHP control flow is currently calling the container?"

Possible carriers:

```text
sequential/root PHP
PHP Fiber A
PHP Fiber B
Swoole coroutine 12
OpenSwoole coroutine 44
```

Carrier detection remains an internal InterMix mechanism.

## 6.2 Logical scope context

Logical scope context answers:

> "Which DI scope instance should this carrier resolve scoped services from?"

Examples:

```text
request R1
job J8
command C4
nested transaction T2
```

A logical scope context must be represented by an **opaque InterMix-owned context/handle**, not by assuming a Fiber ID, coroutine ID, HTTP request ID, UUID string, Runwire request ID, or framework-specific identifier.

The exact public class/method names can be finalized during implementation, but the required semantics are frozen by this plan.

Conceptual API only:

```php
$scope = $container->captureScopeContext();

$result = $container->withinScopeContext(
    $scope,
    static fn ($container) => doWork($container),
);
```

Equivalent attach/detach forms may be provided if they produce cleaner framework integration.

---

# 7. Backward-compatible default behavior

InterMix 10.1 must **not silently propagate parent scope state into arbitrary new Fibers/coroutines**.

Existing behavior remains:

```text
Fiber A + enterScope('request') => isolated scope A
Fiber B + enterScope('request') => isolated scope B
```

Explicit propagation is opt-in:

```text
parent Fiber
    enter request scope S
    capture S
       │
       ├─ child Fiber A attaches S
       └─ child Fiber B attaches S
```

This preserves current users while enabling Foundation/Runwire to model structured request ownership correctly.

---

# 8. Required scope-context semantics

The scope-context primitive must satisfy all of the following.

## 8.1 Opaque and container-bound

A captured scope context:

- is opaque to application code;
- cannot be forged from a string request ID;
- is bound to the originating container/runtime graph;
- cannot be attached to an unrelated container;
- cannot be serialized for another process;
- must not be persisted in cache/database/session state;
- becomes invalid after its owning logical scope is closed.

## 8.2 Process-local only

Scope-context propagation is in-process execution plumbing.

Cross-process propagation belongs to explicit DTO/message/correlation metadata, never to InterMix container scope handles.

## 8.3 Explicit attachment

A child execution carrier attaches the captured context for the duration of a callback/lease and must detach in `finally`.

No InterMix caller should need to manually manipulate internal Fiber/coroutine identifiers.

## 8.4 Parent service identity

When a child attaches the same logical scope, already/materialized and subsequently resolved `Scoped` services belong to that same logical scope.

The intended semantic is:

```text
parent request scope S
  get(DatabaseRequestState::class) -> object X

child A attached to S
  get(DatabaseRequestState::class) -> object X

child B attached to S
  get(DatabaseRequestState::class) -> object X
```

This is what makes `Scoped` continue to mean request/job scope rather than "current Fiber only" when a framework deliberately propagates the scope.

## 8.5 Child nested scopes

A child that enters a nested scope must not mutate the parent's or sibling's active-scope pointer.

Required model:

```text
shared request scope S

parent carrier  -> S
child A carrier -> S -> nested A1
child B carrier -> S -> nested B1
```

`A1` and `B1` are independent child frames parented to `S`.

Leaving `A1` restores child A to `S` only. It must not alter child B or the parent.

This requires the internal implementation to separate **carrier-local active-scope position** from **shared logical scope storage**.

## 8.6 Scope ownership and attachments

The owning scope must not be destroyed while attached child executions are still active.

Preferred safety rule:

- attachment count/leases are tracked;
- child attachment is always detached in `finally`;
- attempting to close the owning scope while child attachments remain fails deterministically rather than silently clearing shared state beneath active children;
- structured runtimes should join children before the owner closes the scope.

Do not hide an ownership bug by leaking a scope indefinitely.

## 8.7 Scope-leave hooks

Scope-leave hooks must remain deterministic:

- the owning logical scope's leave hook runs exactly once;
- attaching/detaching a child to the same scope does not run the owning scope's leave hook;
- a nested child scope runs its own hook when that nested scope closes;
- hook behavior must match dynamic, compiled and deoptimized containers.

---

# 9. Internal scope-state refactor

The existing state model should be refactored only as much as needed to support the semantics above.

## 9.1 Preserve the sequential fast path

Plain PHP/request-scoped execution is the most portable path and must stay cheap.

Do not allocate scope-context/attachment infrastructure when the application never captures/attaches a scope across carriers.

The sequential path should continue to use direct current-scope state where possible.

## 9.2 Carrier-local active frame

Each physical carrier needs its own current-frame pointer/stack.

Do not make one shared mutable `currentScope`/stack serve multiple child Fibers.

## 9.3 Shared logical scope storage

The shared scope context owns the scoped-resolution/seeding identity for that logical scope.

Nested frames reference their logical parent but carrier activation remains independent.

## 9.4 Dynamic runtime

Refactor `ConcurrentRepository` / `ExecutionScopeStore` so it can:

- retain current 10.0 isolated-Fiber behavior;
- capture the active logical scope context;
- attach another carrier to that context;
- keep carrier-local nested scope position;
- share scoped-resolution identity only when explicitly attached;
- clean all context/attachment bookkeeping when the logical scope ends.

Avoid stringly typed request IDs as internal ownership keys.

## 9.5 Production runtime

`ProductionContainer` must implement the same logical behavior without pulling development Repository/reflection machinery into the generated hot path.

The generated runtime must continue to use compact compiled slots/direct methods.

Do not regress InterMix 10's core rule that fully static production artifacts remain free of dynamic Repository/reflection machinery.

## 9.6 Dynamic fallback islands

When a `ProductionContainer` uses a dynamic fallback/runtime island:

- both sides must observe the same logical scope context;
- compiled scoped identity must not split from fallback scoped identity;
- capture/attach must synchronize the fallback exactly as current enter/leave behavior does;
- deoptimization must preserve active singleton/scoped identities where currently guaranteed.

---

# 10. Carrier detection hardening

`ExecutionContext` should be treated as a **carrier detector**, not the authoritative logical request/job identity.

Review and harden the current implementation.

## 10.1 PHP Fiber identity

Avoid depending on reusable numeric `spl_object_id()` values as the long-term owner of leaked scope state where a safer object/weak-reference based mapping can be used without harming the hot path.

A stale abandoned scope must not become visible to a later Fiber merely because PHP reused an object ID.

## 10.2 Swoole/OpenSwoole identity

Preserve optional Swoole/OpenSwoole coroutine isolation without production dependencies.

Where runtime APIs expose a coroutine-local context object suitable for safe lifecycle binding, evaluate it against integer-CID keyed storage.

If numeric CIDs remain the best mechanism, require deterministic cleanup and regression tests for identifier reuse.

## 10.3 Mixed Fiber + Swoole environments

The current priority of PHP Fiber over Swoole/OpenSwoole coroutine identity must be explicitly tested in mixed runtimes.

A Runwire/Swoole host may execute Fiber-based structured tasks inside a Swoole worker/coroutine. Explicit logical scope attachment must remain authoritative regardless of which physical carrier detector wins.

---

# 11. Scoped service construction under concurrent children

Sharing one logical request scope across child tasks introduces a correctness case InterMix 10.0 does not need to solve today: two child tasks may attempt to cold-resolve the same scoped service while one factory/construction path has suspended.

InterMix must never silently produce two different instances for one logical scoped service.

Because InterMix is runtime-neutral and must not depend on an async scheduler/lock, 10.1 should use a deterministic construction guard rather than inventing an awaitable DI lock.

Required behavior:

- mark a scoped service as in-flight while constructing it inside a shared logical scope;
- normal recursive dependency cycles keep current cycle/error semantics;
- if another carrier reaches the same in-flight scoped service before construction completes, fail deterministically with a clear container/concurrency error rather than duplicate-create or deadlock;
- clear the in-flight marker in `finally`, including constructor/factory failure;
- already-resolved scoped services remain cheap reads.

Documentation should recommend resolving critical request-scoped infrastructure before launching parallel child work when shared identity is required.

InterMix service construction itself remains synchronous/runtime-neutral; asynchronous application I/O should happen after service construction unless the application deliberately accepts the collision semantics.

---

# 12. Cancellation, exceptions and cleanup

InterMix must not import Runwire cancellation types or implement a cancellation framework.

Instead, its scope primitives must be exception-safe for **any** throwable, including Runwire cancellation/deadline exceptions.

## 12.1 `withinScope()`

Keep `withinScope()` as the recommended basic boundary. Its `finally` cleanup remains authoritative.

## 12.2 Attached-scope callback

The new captured-scope attachment helper must also detach in `finally` under:

- success;
- ordinary exception;
- Runwire cancellation;
- deadline expiry;
- child-task failure;
- nested-scope failure.

## 12.3 Framework emergency reset primitive

Add or expose a narrowly-scoped, idempotent cleanup primitive suitable for framework/runtime `finally` resetters.

Required semantics:

- clear only the current execution carrier's active scope attachments/nested frames;
- preserve singleton/configuration state;
- never clear another concurrent carrier's active state;
- run required scope-leave semantics for owned nested scopes in deterministic LIFO order;
- never destroy a shared owning scope while other attachments remain;
- be safe to call when the carrier is already at root.

Exact method naming can be finalized during implementation.

Foundation can then register this cleanup in its request/job execution-finalization path without InterMix depending on Foundation or Runwire.

---

# 13. Persistent-process state audit

Runwire makes long-lived PHP processes normal, so 10.1 must explicitly classify InterMix's process-wide mutable/static state.

## 13.1 `Container::$instances`

`Container::instance($alias)` is application/process lifetime state.

Rules:

- Foundation/Webrick must use stable application aliases, never per-request random aliases;
- document that request/job state belongs in scoped services, not container aliases;
- retain `unset()` cleanup behavior;
- add diagnostics/tests if needed to prove aliases do not accumulate during repeated request scopes.

Do not automatically clear the application container after every request.

## 13.2 Reflection caches

`ReflectionResource` is already bounded and uses a `WeakMap` for Closure reflection.

Verify under long-running churn that:

- configured cache limits remain honored;
- Closure entries disappear when closures are released;
- request teardown does not need to clear safe process-level reflection metadata;
- compiled production hot paths still avoid reflection as designed.

No redesign is needed unless the tests expose a leak.

## 13.3 Callable descriptor cache

The `Container::parseCallable()` descriptor cache is already bounded.

Retain the bound and add persistent-churn evidence; no request reset should be required.

## 13.4 `Fence`

`Fence` instance registries are process-global by design.

Document clearly:

- Fence is not a request-scoped storage mechanism;
- applications needing request/job-local identity must use InterMix scoped DI;
- requirement caches remain bounded;
- consumers must use `clearInstances()` / `reset()` when their own lifecycle requires it.

Do not make Fence implicitly request-aware.

## 13.5 `MacroMix`

Macro registries are process/class configuration state.

For persistent/concurrent runtimes:

- macro registration/mixing belongs at bootstrap/build time;
- avoid runtime mutation while requests/jobs are concurrently executing;
- verify existing locking/mutation behavior and documentation;
- do not introduce Runwire locks or scheduler dependencies.

## 13.6 Other static/global caches

Scan every static mutable cache/registry in `src/` and classify it as one of:

```text
immutable/configuration lifetime
bounded process cache
weak-reference cache
explicitly resettable process state
unsafe request-derived state
```

Any cache that can grow from request-controlled cardinality must be bounded, weakly referenced, or removed.

No request-specific principal/session/request/job object may survive in static process state.

---

# 14. Mutation/deoptimization while concurrent scopes are active

InterMix documentation already warns consumers not to mutate definitions, switch environment, attach fallbacks, or deoptimize while concurrent execution is in flight.

10.1 should turn critical cases from documentation-only rules into runtime guards where practical.

At minimum audit and guard:

- `ProductionContainer::deoptimize()`;
- fallback attachment/replacement;
- environment switching;
- definition/configuration mutation paths that invalidate scoped/singleton resolution state;
- production builder/finalization misuse after concurrent execution has started.

When active logical scopes/attachments exist, unsafe global graph mutation should fail deterministically instead of clearing state underneath live requests.

Do not add heavy synchronization to ordinary `get()` paths. Configuration mutation is cold-path behavior and can afford validation.

---

# 15. Runwire 1.0 integration contract

InterMix source code remains Runwire-neutral. Integration is demonstrated in tests/examples only.

The preferred downstream bridge is:

```text
Foundation request/job begins
        ↓
InterMix enters logical scope
        ↓
capture InterMix scope context
        ↓
Runwire CoroutineScope TaskLocal(SNAPSHOT)
        ↓
child task receives captured context
        ↓
child attaches InterMix context in finally-safe wrapper
        ↓
child resolves request/job Scoped services
        ↓
detach child
        ↓
Runwire structured join
        ↓
Foundation leaves owning InterMix scope
```

Conceptual integration example only:

```php
$captured = $container->captureScopeContext();
$key = new TaskLocal();

$runtime->run(function (CoroutineScope $scope) use ($container, $captured, $key) {
    $scope->setLocal($key, $captured);

    $a = $scope->spawn(function () use ($scope, $key, $container) {
        return $container->withinScopeContext(
            $scope->local($key),
            static fn ($c) => $c->get(RequestScopedService::class),
        );
    });

    return $a->await();
});
```

The final API may differ, but the ownership direction must not:

```text
Runwire does not know InterMix
InterMix does not know Runwire
Foundation/consumer composes both
```

---

# 16. Runwire integration test matrix

Add a dedicated integration suite against released Runwire 1.0.

It must cover both development `Container` and generated `ProductionContainer`; where relevant also cover deoptimized production mode.

## 16.1 Baseline

- InterMix operates normally with no Runwire classes loaded.
- ordinary sequential scope behavior is unchanged;
- independent raw Fibers remain isolated by default;
- existing Swoole/OpenSwoole behavior remains optional.

## 16.2 Explicit structured propagation

- parent request scope captured successfully;
- Runwire child task attaches captured scope;
- parent and child resolve the same scoped instance;
- sibling children attached to the same scope resolve the same scoped instance;
- scope seeds are visible to attached children;
- `null` seeds retain current semantics;
- singleton services remain shared as before;
- transient services remain fresh as before.

## 16.3 Nested child scopes

- child A enters nested scope A1;
- child B enters nested scope B1;
- A1 does not become B1's current scope;
- leaving A1 restores child A to shared parent scope;
- parent remains on shared parent scope throughout;
- nested scoped identities are isolated;
- parent scoped identity is restored exactly.

## 16.4 Structured failure

Test cleanup after:

- child success;
- child exception;
- fail-fast sibling cancellation;
- explicit task cancellation;
- Runwire request deadline expiry;
- nested-scope exception;
- parent callback exception;
- cancellation while nested scope is active.

After every case:

- child attachment count returns to zero;
- no carrier retains stale active state;
- a subsequent request/job gets fresh scoped instances;
- hooks have correct call counts.

## 16.5 Ownership violations

Assert deterministic rejection of:

- attaching a scope context to another container;
- using a context after its owner scope closed;
- serializing/restoring a context as if portable;
- owner leaving while live child attachments remain;
- double-detach/double-close misuse where applicable.

## 16.6 Concurrent cold resolution

Exercise two children resolving the same not-yet-created scoped service where the first construction yields/suspends.

Required result is deterministic guard behavior; never duplicate logical scoped instances and never deadlock.

## 16.7 Sequential persistent worker

Simulate many work items on one process/container:

```text
R1 -> cleanup
R2 -> cleanup
R3 -> cleanup
...
```

Prove no cross-request leakage of:

- seeds;
- scoped services;
- current scope;
- nested scope stack/frame;
- captured handles;
- attachment bookkeeping;
- scope-leave state.

## 16.8 Distinct concurrent roots

Two independent logical roots must remain isolated even if both use the same semantic scope name such as `request`.

Scope **name** is not scope **identity**.

---

# 17. Swoole/OpenSwoole compatibility

InterMix must continue to work directly under Swoole/OpenSwoole without Runwire.

Add/retain optional verification for:

- direct coroutine-local scope isolation;
- repeated coroutine-ID reuse after cleanup;
- mixed host coroutine + PHP Fiber behavior;
- explicit logical scope attachment overriding physical-carrier differences;
- no extension class lookup on the ordinary hot path after bootstrap resolution.

Do not add `ext-swoole` or `ext-openswoole` to production requirements.

---

# 18. Compiled production parity requirements

InterMix 10.1 cannot regress the major performance architecture delivered in 10.0.

For every new scope feature, prove parity across:

```text
Container (dynamic/development)
ProductionContainer (fully compiled)
ProductionContainer + runtime islands
ProductionContainer after explicit deoptimization
```

The generated artifact must remain free of unnecessary:

- Repository machinery;
- general reflection resolution;
- Runwire references;
- Swoole references;
- framework references;
- event-loop/concurrency primitives.

The scope-context support included in compiled production code should be the minimum state needed for scoped correctness.

---

# 19. Performance requirements

Correctness comes first, but this release must preserve InterMix 10's hot-path purpose.

## 19.1 Common sequential path

Existing sequential production `get()` / compiled-scoped resolution should remain within measurement noise of 10.0.

A target guardrail of roughly **<= 3% sustained regression** on representative successful production paths is acceptable only when explained by required correctness; larger regressions require redesign or evidence.

Do not place:

- Runwire `class_exists()` checks;
- task-local reads;
- callback dispatch;
- locking;
- allocation-heavy context wrappers

inside every ordinary service lookup.

## 19.2 Concurrent isolated-Fiber path

The existing InterMix 10 Fiber-isolated behavior should remain similarly efficient.

## 19.3 Explicit attached-scope path

Benchmark the new propagation path separately:

- capture cost;
- attach/detach cost;
- resolved scoped read cost under an attached child;
- nested child-scope enter/leave;
- compiled vs dynamic behavior.

The explicit path may cost more than the sequential path, but the overhead must be bounded and proportional to actual structured-concurrency use.

## 19.4 Memory

Run repeated request/task churn and confirm memory stabilizes.

Track at minimum:

- execution carrier entries;
- logical scope contexts;
- attachment records;
- resolved scoped entries;
- static cache sizes;
- container alias registry size.

No completed Runwire task should keep an InterMix scope context alive unintentionally.

---

# 20. Benchmarks

Retain the existing PHPForge benchmark workflow and add focused cases rather than creating a separate benchmark framework.

Extend or add benchmarks for:

- sequential scoped resolution;
- raw interleaved Fiber scoped resolution;
- captured/attached logical scope resolution;
- nested attached scopes;
- production compiled request path;
- repeated persistent request churn;
- context capture/attach/detach micro-cost.

A Runwire-backed benchmark is useful as integration evidence, but the fundamental InterMix benchmark should also be reproducible with raw Fibers so the library's own performance evidence is not dependent on Runwire installation.

---

# 21. Documentation updates

Update documentation as part of the release, not after it.

## 21.1 `docs/di/scopes.rst`

Document:

- carrier identity vs logical scope identity;
- default independent-Fiber behavior;
- explicit capture/attach semantics;
- structured child tasks;
- nested child scopes;
- ownership/lifetime rules;
- long-running worker cleanup;
- shared scoped-service concurrency warning;
- examples using generic Fibers first.

## 21.2 Framework/runtime interoperability

Add a small framework-neutral section describing how a runtime such as Runwire can propagate an opaque InterMix scope context through its own task-local mechanism.

Keep the example optional; InterMix must not present Runwire as a requirement.

## 21.3 Development vs production

Update the InterMix 10 development/production docs to confirm the new scope semantics are identical in dynamic and compiled runtimes.

## 21.4 Persistent process state

Document which public features are process/application lifetime:

- `Container::instance()` alias registry;
- MacroMix registry;
- Fence instances;
- bounded reflection/callable metadata caches.

Make clear which are unsuitable for request-specific data.

---

# 22. Public API discipline

10.1 is a minor release.

Prefer additive API only.

Do not break:

- existing `Container` scope methods;
- `ContainerBuilder` usage;
- generated production loading;
- PSR-11 behavior;
- current default Fiber/coroutine isolation;
- existing `withinScope()` callback behavior.

New scope-context types/methods should be small, explicit and framework-neutral.

Avoid a generic "runtime adapter" abstraction inside InterMix. InterMix owns DI scope/lifetime semantics, not HTTP, workers, processes, deadlines or schedulers.

---

# 23. Explicit non-goals

InterMix 10.1 must **not** become responsible for:

- starting/stopping Runwire;
- HTTP request parsing;
- HTTP/1.1, HTTP/2 or HTTP/3;
- worker pools;
- PCNTL/POSIX process supervision;
- Runwire cancellation tokens;
- event loops;
- Fiber scheduling;
- channels/futures/mutexes/semaphores;
- application authorization;
- Foundation execution IDs;
- Webrick request/response semantics;
- Omnibus queue semantics;
- copying all Runwire task-local state;
- cross-process DI scope propagation.

The only overlap is **safe DI scope ownership across execution carriers**.

---

# 24. Downstream handoff contract

After InterMix 10.1 is complete, downstream libraries/frameworks should consume it as follows.

## 24.1 Foundation

Foundation owns semantic request/job/command execution boundaries.

For a Runwire structured execution:

1. Foundation enters the appropriate InterMix scope.
2. Foundation seeds request/job/application context.
3. Foundation captures the InterMix logical scope context.
4. Foundation propagates that opaque value using Runwire task-local state or a Foundation wrapper.
5. Each child attaches/detaches the InterMix context around its callback.
6. Runwire joins/cancels structured children.
7. Foundation leaves the owning scope in `finally`.
8. Foundation invokes the idempotent InterMix execution cleanup/resetter as defense in depth.

## 24.2 Webrick

Webrick should not need to know InterMix internal scope mechanics.

It provides HTTP application semantics and request objects; Foundation composes Webrick request execution with InterMix scopes and Runwire runtime context.

## 24.3 Omnibus

The same mechanism can later propagate one InterMix job/message scope across structured child work inside a message handler.

## 24.4 Plain/shared hosting

No propagation adapter is needed.

One request executes sequentially, Foundation/Webrick uses ordinary `withinScope()`, and all Runwire-specific integration remains absent.

---

# 25. Implementation sequence

Execute in this order.

- [ ] **1. Freeze InterMix 10.0 behavior.** Keep all current scope/Fiber/compiled parity tests green and add regression fixtures for current defaults before refactoring internals.
- [ ] **2. Add explicit terminology/tests for carrier identity vs logical scope identity.** No behavior change yet.
- [ ] **3. Design the opaque scope-context/handle contract.** Keep names minimal; prove foreign/stale/non-serializable ownership rules.
- [ ] **4. Refactor dynamic scope internals.** Separate carrier-local active frame from logical scope storage while preserving the sequential fast path.
- [ ] **5. Implement capture + attach/detach callback/lease semantics in dynamic `Container`.** Default independent Fiber behavior remains unchanged.
- [ ] **6. Implement nested child-frame semantics.** Sibling nested scopes must not mutate each other's active position.
- [ ] **7. Add attachment ownership/liveness enforcement.** Owner cannot silently close while child attachments remain.
- [ ] **8. Add concurrent scoped-construction guard.** Never duplicate one logical scoped instance when construction suspends/interleaves.
- [ ] **9. Add framework-safe current-execution cleanup/reset primitive.** Idempotent, carrier-local and hook-correct.
- [ ] **10. Port the same semantics to generated `ProductionContainer`.** Preserve InterMix 10 compiled hot paths.
- [ ] **11. Validate dynamic fallback/runtime-island synchronization.** Captured scopes and deoptimization retain identity/parity.
- [ ] **12. Harden execution carrier detection.** Review Fiber object-ID reuse, Swoole/OpenSwoole lifecycle and mixed carrier behavior.
- [ ] **13. Guard unsafe global mutation during active concurrent scopes.** Especially deoptimization, environment/fallback/definition state.
- [ ] **14. Audit all static/process-wide InterMix state.** Bound, classify, document or fix each mutable cache/registry.
- [ ] **15. Add released Runwire 1.0 integration tests.** Use coroutine/task-local/cancellation only; no PCNTL/POSIX requirement.
- [ ] **16. Add persistent worker/cancellation/exception stress coverage.** Prove no scope/attachment leakage.
- [ ] **17. Add optional Swoole/OpenSwoole compatibility evidence.** No production extension requirement.
- [ ] **18. Run dynamic/compiled/deoptimized semantic parity suite.** No feature may work only in development mode.
- [ ] **19. Benchmark sequential, Fiber-isolated and attached-scope paths.** Reject unnecessary hot-path regressions.
- [ ] **20. Update docs and migration/release notes.** Explicit structured-concurrency guidance and persistent-process state classification.
- [ ] **21. Perform downstream readiness review for Webrick/Foundation.** Freeze the small InterMix integration surface they should consume.

---

# 26. Detailed acceptance matrix

| Environment / execution model | InterMix requirement | Expected behavior |
| --- | --- | --- |
| Plain sequential PHP | mandatory baseline | ordinary scopes, no concurrency machinery required |
| Apache / CGI / FastCGI | mandatory baseline | request-owned process lifecycle, normal scopes |
| PHP-FPM | mandatory baseline | request-owned lifecycle, normal scopes |
| LiteSpeed/shared hosting | mandatory baseline | normal scopes, no PCNTL/POSIX/worker assumption |
| PHP Fiber independent tasks | mandatory | isolated scopes by default |
| PHP Fiber structured children | mandatory new 10.1 coverage | explicit captured-scope attachment |
| Runwire 1.0 coroutine tasks | integration coverage | task-local propagation composed externally |
| Runwire portable native | downstream compatibility | no InterMix PCNTL/POSIX dependency |
| Runwire prefork | downstream compatibility | process-local container per worker + execution scopes |
| FrankenPHP persistent worker | downstream compatibility | deterministic request scope cleanup |
| RoadRunner persistent worker | downstream compatibility | deterministic request scope cleanup |
| Swoole/OpenSwoole | optional integration coverage | coroutine isolation + explicit logical propagation |
| Compiled ProductionContainer | mandatory | same semantics with generated fast path |

---

# 27. Release gates

InterMix 10.1 is ready only when all of the following are true.

## Correctness

- [ ] Existing InterMix 10.0 scope behavior remains backward compatible.
- [ ] Independent Fibers/coroutines remain isolated by default.
- [ ] Explicit logical scope propagation works across child Fibers.
- [ ] Parent/child/sibling scoped identity matches the documented contract.
- [ ] Nested child scopes are carrier-local and restore correctly.
- [ ] Scope seeds, including `null`, propagate correctly through explicit attachment.
- [ ] Scope-leave hooks run exactly once for the owning scope.
- [ ] Foreign/stale scope contexts are rejected.
- [ ] Owner-close-with-live-attachments is rejected deterministically.
- [ ] Concurrent cold scoped resolution cannot duplicate one logical scoped instance.
- [ ] Cancellation/exception/deadline paths leave no stale scope state.
- [ ] Sequential persistent requests/jobs receive fresh scoped state.

## Runtime independence

- [ ] `infocyph/runwire` is absent from production `require`.
- [ ] No Runwire type appears in InterMix production public APIs.
- [ ] No PCNTL/POSIX requirement is introduced.
- [ ] Plain PHP/shared-hosting behavior remains first-class.
- [ ] `composer install --no-dev --classmap-authoritative` works without Runwire.

## Production/runtime parity

- [ ] Dynamic `Container` passes all new semantics.
- [ ] Fully compiled `ProductionContainer` passes all new semantics.
- [ ] Runtime islands/fallback pass all new semantics.
- [ ] Explicit deoptimization retains required identity/parity.
- [ ] Generated artifacts do not import Runwire/framework/runtime dependencies.

## Persistent safety

- [ ] No request/job objects survive in static process state.
- [ ] static caches are bounded/weak/configuration-only/explicitly resettable.
- [ ] repeated request/task churn stabilizes memory and context counts.
- [ ] container alias count remains stable under normal framework usage.
- [ ] unsafe graph mutation is rejected while active concurrent scopes exist.

## Performance

- [ ] ordinary sequential production request path remains within acceptable measurement noise / stated regression budget.
- [ ] existing Fiber-isolated scope performance is not materially regressed.
- [ ] explicit captured-scope attach/detach overhead is measured and bounded.
- [ ] compiled generated service dispatch remains the normal production fast path.
- [ ] no Runwire/Swoole/runtime detection is added to every ordinary `get()` call.

## Quality/docs

- [ ] PHPForge quality/static-analysis gates pass for the supported PHP matrix.
- [ ] existing InterMix benchmarks remain green.
- [ ] new structured-scope benchmarks are documented.
- [ ] `docs/di/scopes.rst` reflects the final semantics.
- [ ] persistent-process state ownership is documented.
- [ ] downstream Foundation/Webrick integration contract is frozen and small.

---

# 28. Final target architecture

```text
                         Application / Foundation
                                  │
                     semantic request/job scope
                                  │
                                  ▼
                              InterMix 10.1
                ┌─────────────────┴─────────────────┐
                │                                   │
        physical carrier                     logical scope
      Fiber / coroutine / root              opaque context
                │                                   │
                └──────────────┬────────────────────┘
                               │
                     Scoped service lifetime
                               │
             ┌─────────────────┼──────────────────┐
             │                 │                  │
          parent            child A            child B
             │                 │                  │
             └──── same logical request/job scope ┘

Runwire 1.0
    └─ owns task scheduling, task-local propagation,
       cancellation, deadlines, event loop and runtime

InterMix
    └─ owns DI scope identity, scoped service lifetime,
       scope attachment, cleanup and compiled parity
```

And on the lowest-common-denominator environment:

```text
ordinary PHP request
        ↓
Foundation/Webrick
        ↓
InterMix withinScope()
        ↓
handler
        ↓
finally leave scope
```

No Runwire, Fiber scheduler, PCNTL, POSIX or persistent worker is required.

---

# 29. Definition of done for downstream work

The InterMix pass is complete when Webrick/Foundation can rely on a small, stable statement:

> InterMix 10.1 provides request/job scoped DI that remains isolated by default across independent Fibers/coroutines, can be explicitly propagated across structured child execution carriers through an opaque framework-neutral scope context, cleans up deterministically under persistent workers/cancellation, and preserves identical semantics in dynamic and compiled production containers without requiring Runwire or any long-running runtime.

Only after that contract is proven should the ecosystem move upward to the Webrick Runwire adapter and Foundation 3 runtime composition.
