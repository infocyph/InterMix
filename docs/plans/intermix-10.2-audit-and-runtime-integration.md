# InterMix 10.2 audit and runtime integration plan

Status: proposed; audit completed, implementation not started.

Audited on 2026-09-29 against InterMix **10.1.1**, commit
`f687452b9b8d10333e7beb7d6905b479b2b8480e`.

This plan follows [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md)
and its agent workflow.
Correctness, security, isolation, and host ownership precede performance.
Changes must preserve public contracts and named parameters, remain optional at
provider boundaries, and pass existing detectors without weakening them.

## Release decision

**Plan work; do not call the current library perfect or ready for the proposed
runtime integration.** Three additional probes expose defects despite a green
existing suite.

- Target **10.2.0** for the fixes, verified CacheLayer 4.0 support, and an additive,
  optional Runwire 2.1 integration.
- If fixes need to ship independently, release **10.1.2** with the correctness
  fixes and their regressions first, then 10.2.0 for the integration.
- A major release is not currently justified. Updating development dependencies
  does not itself break InterMix's runtime dependency contract. Reconsider a major
  only if implementation requires removing existing scope APIs, changing lifetime
  semantics, raising the PHP floor, or breaking public signatures.
- Keep PHP `>=8.4`, PSR-11, and PSR-6. CacheLayer, Runwire, and Opis remain optional
  production integrations. Do not make Runwire's 64-bit requirement a new floor
  for ordinary InterMix use.

## Audit coverage and evidence

The audit combined the existing code graph, configured whole-project detectors,
the full test suite, and targeted source review/probes of DI resolution and
lifetimes, dynamic/compiled scope ownership, generated artifacts, external
definition caching, closure serialization, process state, Fence, Remix, helpers,
dependencies, documentation, and CI. This is not a guarantee that every possible
vulnerability has been eliminated, nor a production load or penetration test.

| Check | Observed result |
| --- | --- |
| Host | PHP 8.5.4 CLI NTS, Composer 2.10.3 |
| PHPForge diagnostics | `ic:doctor` healthy; `ic:list-config` and `ic:active-config` inspected |
| `composer ic:tests:details` | Exit 0; **399 tests, 2,286 assertions** |
| Whole-project checks | Syntax/reference checks: 210 PHP files; comment checks: 111 files; Pint, PHPCS, Deptrac, PHPStan, Psalm, Rector dry-run passed |
| Diagnostic qualifications | Duplicate detector reports **28 clone groups / 1,072 duplicated lines / 5.45%**, with current status PASS; Deptrac reports **77 uncovered**, zero violations |
| `composer audit --format=json` | Zero known advisories; abandoned `doctrine/annotations`, brought in by development benchmark tool `phpbench/phpbench` 1.7.0 |
| `composer ic:release:guard` | Exit 0; audit succeeded after a transient registry retry; abandonment is an explicitly non-blocking warning in this guard |
| Released-source compatibility probe | Full suite: **399 tests, 2,286 assertions**, with CacheLayer 4.0 and Runwire 2.1 classes loaded from their clean sibling checkouts |
| Focused released-source probe | CacheLayer + Runwire integration tests: **16 tests, 77 assertions** |
| Actual APCu execution | CacheLayer 4.0 integration suite with `php -d apc.enable_cli=1`: **11 tests, 56 assertions**; SQLite also available |

The released-source probe used a temporary autoload overlay and explicitly
verified class source paths. It did **not** update this project's Composer
constraints, installed metadata, lockfile, or dependencies, and it does not prove
clean Composer installation or full dependency-range compatibility.

Ordinary host runs have APCu installed but disabled for CLI. Swoole/OpenSwoole are
absent. Their tests exercise absence branches here; green counts do not prove
extension-runtime behavior. PHP 8.4, real Swoole/OpenSwoole, other operating
systems, external cache servers, production RPM, and final-revision CI were not
verified by this audit.

Live Composer registry metadata and clean local release commits agree:

| Package | Installed here | Released target | Release commit |
| --- | --- | --- | --- |
| InterMix | 10.1.1 | Current baseline | `f687452b9b8d10333e7beb7d6905b479b2b8480e` |
| CacheLayer | 3.4; constraint `^3.2.0` | 4.0 | `58ae96dfe14ee45a528247c00c6a3d727160f833` |
| Runwire | 1.0; constraint `^1.0` | 2.1 | `178308361772d4e995040a8ab84d4df876579078` |

Primary release sources: [CacheLayer metadata](https://repo.packagist.org/p2/infocyph/cachelayer.json),
[Runwire metadata](https://repo.packagist.org/p2/infocyph/runwire.json),
[CacheLayer 4.0 migration guide](https://github.com/infocyph/CacheLayer/blob/4.0/docs/upgrade-4.0.rst),
and [Runwire 2.1 source](https://github.com/infocyph/Runwire/tree/2.1).
Search-engine snippets lag these releases and were not used to determine versions.

## Confirmed findings

### F1 — Independent concurrent resolutions falsely report a circular dependency

Priority: **high; fix before expanding concurrent-runtime support**.

`src/DI/Resolver/DefinitionResolver.php:233` tracks construction using
`$entriesResolving[$name]` on one shared resolver. This is not carrier-local.
Two independent request scopes resolving the same scoped factory can therefore
be mistaken for recursive resolution if the first factory suspends.

Minimal reproduction, after loading `vendor/autoload.php`:

```php
$container = new Infocyph\InterMix\DI\Container('audit.concurrent');
$container->bindFactory('scoped', static function () {
    Fiber::suspend();
    return new stdClass();
}, Infocyph\InterMix\DI\Support\LifetimeEnum::Scoped);
$run = static function () use ($container) {
    $container->enterScope('request');
    try {
        return $container->get('scoped');
    } finally {
        $container->leaveScope();
    }
};
$first = new Fiber($run);
$second = new Fiber($run);
$first->start();
$second->start(); // ContainerException: Circular dependency for definition 'scoped'.
```

These are separate logical request scopes, not two children racing to construct
one shared scoped instance. The existing shared-scope construction guard must
remain intact. The confirmed impact is a spurious request failure; this probe
does not demonstrate cross-request data disclosure.

Fix construction ancestry and cycle-detection state at the correct execution
boundary. Also inspect `ClassResolver::$entriesResolving`, class/definition trace
stacks, missing-resolution hooks, and fallback paths for the same ownership error.
Do not blindly make singleton construction independent: singleton identity needs
its own deterministic cross-carrier contention behavior.

Acceptance: interleave suspended factories/constructors in independent scopes;
both complete with distinct scoped objects. True same-carrier cycles still fail.
Shared logical scopes retain their one-instance/contended-construction contract.
Exceptions and cancellation clear tracking state. Cover dynamic, compiled, and
deoptimized/fallback execution, plus diagnostics enabled and disabled.

### F2 — Last Fiber fast path retains completed work

Priority: **medium; persistent-worker lifecycle defect**.

`src/DI/Internal/ExecutionContext.php:35` holds a strong static `$lastFiber`.
`fiberCarrierId()` assigns the running Fiber to it. Returning to the main carrier
does not release it. A completed Fiber can retain its return value and the entire
reachable result object graph until another Fiber replaces it.

Observed: a Fiber called `ExecutionContext::id()`, returned a new object, and
terminated. After removing its external reference, collecting cycles, and calling
`ExecutionContext::id()` on the main carrier, WeakReferences to both the Fiber and
its returned object remained live. This is bounded to the latest Fiber, but its
payload size and idle retention time are not bounded by InterMix.

Use weak ownership for the fast-path carrier reference, preserving stable token
identity for live/suspended carriers. Do not require another request or an explicit
cache reset to collect completed work. Update the 10.1 process-state audit, whose
weak-ownership description is incomplete for this field.

Acceptance: WeakReferences clear after owner references are dropped, including
after the final request in an idle worker; suspended live Fibers retain stable
IDs; existing token-reuse and scope-isolation tests pass. Benchmark this hot path.

### F3 — Recursive cache-value validation can exhaust the process

Priority: **medium; resource-exhaustion risk, dependent on supplied values**.

`src/DI/Resolver/Repository.php:1025` recursively walks arrays with `array_all()`
without cycle, depth, or work limits. The public container path reproduces this:

```php
$container = new Infocyph\InterMix\DI\Container('audit.cycle');
$container->definitions()->enableDefinitionCache(
    Infocyph\CacheLayer\Cache\Cache::memory('audit.cycle'),
);
$container->bindFactory('value', static function (): array {
    $value = [];
    $value['self'] = &$value;
    return $value;
});
$container->get('value');
```

In a child process bounded by a three-second timeout and `memory_limit=32M`, this
produced memory exhaustion and exit 255. The optional cache's fail-open behavior
does not recover from this fatal exhaustion. It is not evidence of an unauthenticated
remote exploit: a factory/cache/input path must supply such a structure.

Bound traversal before recursion consumes process resources. For cache admission,
cyclic or over-budget values should remain usable in-process while bypassing
external persistence. Review the repeated recursive exportability checks in
`FactoryDefinition`, static planners, and `AutomaticClassCompiler`; unsupported
build inputs need a controlled failure or existing dynamic fallback, not a fatal
process exit. Preserve null/false/zero, array keys, ordinary nested arrays, and
reference semantics without mutating caller data.

Acceptance: cyclic, deeply nested, broad, and object-containing arrays; ordinary
values; read hits, misses, warmup, strict/fail-open cache modes, and compilation.
Subprocess tests must demonstrate bounded memory/work and nonfatal outcomes.

### F4 — Dependency coverage and integration documentation are behind releases

Priority: **required for the proposed minor release**.

The current dev constraints exclude CacheLayer 4 and Runwire 2. Existing Runwire
tests manually set a `TaskLocal`, capture InterMix's `ScopeContext`, and attach it
inside each spawned task. Production InterMix code has no Runwire context bridge.
`docs/di/cache.rst` still recommends CacheLayer 3.2.

The released-source tests establish a useful compatibility baseline, not a reason
to skip dependency installation, lifecycle, failure, and capability tests.

### F5 — Follow-up quality and security documentation work

- Classify all 28 duplicate groups. Centralize genuine shared policy and update
  every affected caller, particularly recursive admission/exportability rules.
  Preserve intentionally distinct dynamic/generated semantics and measured hot
  paths; do not create a general abstraction solely to reduce a metric.
- Review the 77 Deptrac-uncovered dependencies before claiming complete
  architecture coverage. Do not turn current diagnostics into an invented
  failing gate, or hide findings through exclusions or suppressions.
- Track the abandoned development-only Doctrine annotations dependency through
  PHPBench/PHPForge upstream. Do not remove benchmark coverage or suppress audit
  findings to eliminate the warning.
- Clarify that unsigned closure deserialization accepts trusted executable input
  only, and that signing authenticates the producer; it does not sandbox code.
  Existing signed parsing checks size and HMAC before Opis deserialization, and
  Invoker does not implicitly decode closure envelopes. No signature bypass was
  established in this audit.
- Keep generated PHP paths and deployment metadata trusted and immutable. xxh128
  fingerprints are change-detection identities, not authentication. Individual
  file renames do not make a PHP artifact and its sidecar an atomic deployment
  pair; publish complete versioned build directories before switching traffic.
- Revisit retry delay overflow/non-finite backoff and cancellation behavior when
  adding cooperative waits. These are review targets, not reproduced exploits.

## CacheLayer 4.0 work

1. Change the development target to `infocyph/cachelayer:^4.0` and test a clean
   install. Keep `CacheItemPoolInterface` as the only production cache boundary;
   never instantiate CacheLayer implicitly in DI resolution.
2. Continue caching only safe scalar/null/array singleton values. Scoped/transient
   values, service objects, resources, and closures remain excluded. Do not enable
   CacheLayer object or closure deserialization to make integration tests pass.
3. Extend tests for real PSR-6 null hits, deferred writes, rejected writes/commit,
   malformed values, generation changes, tier promotion, and F3. Preserve InterMix's
   explicit `failOpen` behavior without promising overrides of provider-level policy.
4. Verify memory, SQLite, APCu-enabled CLI, tiered, and documented Node Cache
   examples. Add a real network backend job if advertising its verified support;
   do not silently replace shared storage with process memory.
5. Update cache/installation documentation. Applications own pool configuration,
   namespace, integrity policy, TTL, backend resources, and lifecycle. Use a cold
   CacheLayer 4 namespace or a coordinated migration; do not mix 3.x/4.0 writers
   against mutable state without a separately proven compatibility contract.
6. Preserve generic PSR-6 behavior and the existing 3.4 consumer compatibility
   lane where practical. A new dev target does not make a conforming old pool
   unusable. Explicitly document which optional provider versions are tested.

## Runwire 2.1 design

### Boundary and ownership

Add one cohesive optional integration entry point, provisionally
`Infocyph\InterMix\Integration\Runwire\RunwireIntegration`, associated with a
specific `Container` or `ProductionContainer`. Its separate existence is justified
by the provider boundary and request/task lifecycle. Reuse existing scope APIs and
storage; do not add a parallel DI container or generic runtime abstraction family.

Runwire 2.1 exposes concrete `RuntimeContext`, `RequestContext`, `CoroutineScope`,
`TaskLocal`, and `RuntimeCapability` APIs. Inspection found no public universal
ambient current-runtime/current-scope accessor to use. Therefore **automatic means
capability selection after the framework shares its actual context**, not runtime
discovery or construction by InterMix.

| Boundary | Proposed behavior |
| --- | --- |
| Worker bootstrap | Bind the host's actual `RuntimeContext` after worker/fork/generation creation. Validate ownership once. |
| Request/task entry | Share the active `RequestContext` and optional `CoroutineScope`; validate matching runtime identity and reject completed/stale bindings. Seed scoped context services without mutating global definitions per request. |
| DI scope | Enter one owned logical scope, or attach an explicitly supplied existing `ScopeContext`; record which operation occurred so cleanup closes only owned scopes. |
| Child work | Capture the logical DI handle and propagate it using Runwire task-local snapshots plus a bridge-provided callback wrapper at the host's spawn boundary. The child explicitly attaches/detaches through existing APIs. |
| Exit | Unwind nested integration state in `finally`; detach children before owner scope close. Preserve the primary failure while reporting cleanup failures according to existing contracts. |
| Worker shutdown/replacement | Release this bridge's binding and request/task references. Host coordinates child settlement, drain, replacement, and resource disposal. |

Raw `CoroutineScope::spawn()` does not execute arbitrary InterMix lifecycle hooks.
Do not promise propagation for tasks bypassing the bridge wrapper. Snapshot
inheritance must not be described as copying service objects: children share the
intended logical scope; unrelated request scopes remain isolated. Background jobs
that outlive a request must receive a new job scope, not retain its request handle.

### Capability selection and fallback

| Situation | InterMix behavior |
| --- | --- |
| Runwire absent or integration unused | Existing synchronous DI and explicit scope behavior; no Runwire initialization, autoload scanning, workers, or listeners |
| Runtime bound, no active task scope | Normal execution; runtime metadata alone must not imply a cooperative scheduler |
| Active scope + `RUNWIRE_COROUTINES` | Use the supplied scope for opt-in cooperative waits and cancellation checkpoints; honor its deadline/token |
| Host-native coroutine capability only | Retain existing Swoole/OpenSwoole carrier support; do not mistake it for Runwire's coroutine API |
| Persistent/concurrent host | Enforce carrier/request-local context and deterministic cleanup; no process-global request principal or scope object |
| Capability unavailable | Use the established synchronous path when equivalent; explicitly requested asynchronous guarantees fail clearly rather than pretending to be available |
| Cancellation/deadline/integrity failure | Propagate failure; do not reinterpret it as a missing capability or retry it automatically |

Use `RuntimeContext::supports(RuntimeCapability::...)`; do not branch on driver
names or infer capabilities from installed extensions. Actual active scope and
liveness still need checking at the execution boundary. Do not freeze changing
request state in a bootstrap capability cache.

The bridge must never create/run a second `Runtime` or `CoroutineRuntime`, start
or stop an event loop, spawn/fork worker processes, install signal handlers, own
listeners, call `RequestContext::complete()`, or close a borrowed CoroutineScope.
Even when capability flags say Runwire owns a loop/pool, ownership is not delegated
to InterMix. No scope handle, context, connection, or container crosses a worker
process boundary through serialization.

### Useful scope of integration

The primary value is consistent DI lifetime/context propagation, cleanup,
cancellation, and deadline handling across host-managed work. Keep capability
lookups outside ordinary warmed `get()` calls; scope activation should prepare the
existing fast path. Do not parallelize service construction or cache warmup merely
because concurrency is available. F1 must be fixed before evaluating either.

Cooperative retry delays are a secondary measurable benefit: current `retry()`
uses blocking `usleep()`. Prefer an additive, explicitly supplied delay callable
that the bridge resolves against its active scope; keep existing positional and
named arguments intact. With no bridge delay, preserve current synchronous use.
Cancellation must not be swallowed by the generic `Throwable` retry loop: define
and test a cancellation-aware predicate/checkpoint contract before shipping this
extension. Defer it if it adds complexity without a representative benefit.

### Transitive sharing across multiple libraries

The framework may pass its existing Runwire context through InterMix into
CacheLayer. Other libraries may also pass that same context into the same
CacheLayer integration. Support this dependency graph explicitly:

```text
Framework (owns runtime and lifecycle)
  ├─ InterMix ──────────── CacheLayer
  ├─ Another library ──── CacheLayer
  └─ Direct use ───────── CacheLayer
```

The execution chain carries the exact same `RuntimeContext`, `RequestContext`,
and active `CoroutineScope` object identities. Do not clone contexts, reconstruct
runtime instances, or give each library a competing scheduler. Each library keeps
its own domain-specific state; the host context, cancellation, deadline, and task
ownership are shared.

Once the host enables this integration path, InterMix forwards context at the
worker and request/task boundaries through CacheLayer's existing integration.
Separate per-request framework setup for every transitive library must not be
required. Keep forwarding optional and outside PSR-6 `getItem()`/`save()` and
warmed DI lookups. A generic PSR-6 pool need not expose Runwire methods; do not
extend that interface or assume every supplied pool is CacheLayer. Resolve the
optional integration once at setup and retain the normal path when it is absent.

CacheLayer 4.0's actual API is `RunwireIntegration::bind(RuntimeContext)` plus
`share(?RequestContext, ?CoroutineScope, callable)`. It accepts supplied instances,
but its worker binding is process-static, not isolated per cache pool:

- `bind()` with the identical runtime instance is idempotent. Participating
  libraries must reuse that instance and validate an existing binding before
  forwarding; a different live binding is a configuration conflict, not permission
  to overwrite it. CacheLayer's current `bind()` would otherwise reset contexts
  and memoizers when switching identities.
- Exactly one host-designated lifecycle owner binds/releases the worker-wide
  integration. The framework may delegate setup through InterMix, but borrowers
  never gain release authority just because they reached CacheLayer first.
  Releasing one container, library, cache pool, or request must not call the shared
  worker `release()` or flush another consumer's state.
- Matching-context nested `share()` calls must preserve the outer execution
  context. CacheLayer currently restores it in `finally`; keep that behavior for
  success, exceptions, and cancellation. Never replace an active request with
  null context simply because an intermediate library has no new context to add.
- Sibling requests/tasks require separate execution bindings. Enter sharing inside
  each spawned child callback; caller-side sharing does not automatically appear
  in a new Fiber. Keep this aligned with InterMix's child-scope wrapper.
- CacheLayer's request memoizers belong to the shared request, not to whichever
  library called first. A borrower finishing must not flush them for siblings.
  Each library still namespaces its cache keys and domain-specific request data.
- Shutdown/replacement drains all borrowers, then the designated owner releases
  the old binding once and installs the new worker-generation context. Do not
  rebind during active `share()` callbacks: their saved outer context must not be
  restored into a replacement generation.

The released CacheLayer bridge supports one bound worker runtime per process.
Do not promise simultaneous distinct runtime bindings in that process without a
separate CacheLayer design change. Its execution storage is Fiber-local with a
root fallback; concurrent native Swoole/OpenSwoole sharing without distinct Fibers
needs a proven carrier-isolation path before being advertised. InterMix's carrier
support alone does not establish downstream CacheLayer isolation.

These rules apply to other participating libraries too. Reuse the existing
provider integration and explicit host lifecycle rather than creating separate
binding registries, reference counters, or wrappers in every library.

Development target: `infocyph/runwire:^2.1`; add an optional Composer suggestion
and documented supported range. Existing generic manual scope propagation can
continue working with 1.0; distinguish that compatibility from the new bridge's
2.1 requirement.

## Implementation sequence and completion criteria

| Order | Work | Completion evidence |
| --- | --- | --- |
| 1 | Establish regression tests for F1–F3 before changing behavior | Each fails on 10.1.1 for the documented reason, with subprocess bounds for exhaustion |
| 2 | Fix construction tracking, weak carrier retention, bounded value traversal | New tests plus dynamic/compiled/fallback parity, same-scope contention, true-cycle, cleanup, and ordinary cache tests pass |
| 3 | Resolve related duplicate policy; classify remaining diagnostics | No detector weakening; shared fixes reach all affected callers; documented disposition of remaining structural diagnostics |
| 4 | Update optional dependency targets and CacheLayer documentation/tests | Clean current/lowest dependency installations; 4.0 backend evidence; standalone PSR-6 behavior preserved |
| 5 | Implement the minimal context/scope bridge | Explicit host binding; automatic capability selection; correct borrowed/owned scope cleanup; no runtime ownership side effects |
| 6 | Add provider lifecycle and absence tests | Matrix below passes, including Runwire completely absent and bridge unused |
| 7 | Measure and document | Stable baseline comparison, representative host RPM, persistent-worker soak, examples, migration/rollback notes |
| 8 | Final release checks | Required local tools and exact-final-revision CI green; unresolved acceptance gates explicitly closed before tagging |

No implementation, dependency changes, tags, commits, or release publication were
performed as part of this audit-and-plan task.

## Verification matrix and release gates

Functional coverage must include:

- Dynamic, generated production, and deoptimized/fallback containers; cold and
  warmed paths; singleton, scoped, transient, aliases, scope seeds, hooks, and null.
- Two interleaved requests with the same scope name and service IDs; nested scopes;
  inherited child tasks; detached jobs; multiple containers/bridges in one process.
- Framework → InterMix → CacheLayer, framework → another library → CacheLayer,
  and direct CacheLayer use in the same worker/request. Assert exact context object
  identity through all paths, independent library cache keys, matching-context
  nested sharing/restoration, and sibling request isolation.
- InterMix teardown while another library still uses CacheLayer; duplicate setup;
  conflicting runtime identity; no release/flush by borrowers; generation change
  after drain; optional providers absent. Verify child callbacks enter sharing and
  cancellation/deadlines propagate without stealing lifecycle ownership.
- Exceptions, explicit cancellation, deadlines, fail-fast sibling cancellation,
  cleanup callbacks that throw, owner close while children remain, and stale handles.
- Worker generation replacement; post-fork binding; shutdown and idle GC; no retained
  request/principal/return graph after completion. Preserve existing stress tests.
- Runwire absent, installed but unbound, bound with unavailable capabilities, and
  active 2.1 task scope; borrowed loop remains live and usable after bridge exit.
- PHP 8.4/8.5 actual runtimes; real Swoole and OpenSwoole CI lanes; production
  `--no-dev --classmap-authoritative` installation without optional providers;
  clean installation with each advertised integration range and prefer-lowest.
- Memory, SQLite, enabled APCu, tiered cache, generic PSR-6 fake/failure contracts;
  provider-specific services only in explicitly provisioned lanes.
- Serializer tampering/wrong-key/oversize/malformed cases and absence of Opis;
  trusted artifact load, manifest mismatch, failed generation, and immutable
  deployment activation. No untrusted artifact execution tests against live paths.

Run PHPForge's instructed diagnostics first. For implementation, run
`composer ic:process`, review its changes, then `composer ic:tests:details` and
`composer ic:release:guard`. Preserve syntax, reference, duplicate, comment,
architecture, complexity, static/security, format, and refactor checks. Never
silence valid failures by changing thresholds, adding baselines, skipping tests,
or excluding source. Review changes to the mutable PHPForge `main` tooling version
when reproducing evidence.

Performance acceptance:

- Establish baseline on 10.1.1 before changing hot carrier/resolution paths. Keep
  the existing 10.0.4 comparison for historical continuity where useful; add the
  immediately previous release comparison for this candidate.
- Run at least five alternating baseline/candidate pairs on the same stable
  environment; separate cold startup, first call, warm resolution, cache hit/miss,
  independent requests, and shared child scopes. Confirm equivalent results.
- Preserve existing component budgets (3% sequential, 5% Fiber) as secondary
  regression signals. They do not establish host-application throughput.
- Add representative host HTTP/job workloads. Use median sustained successful
  RPM/RPS as primary evidence, with the engineering default 2% maximum median RPM
  regression unless established measurement variance justifies a documented
  workload-specific tolerance. Do not claim improvement within noise.
- Record duration, concurrency curve, successful/failed responses, cancellation
  outcomes, p50/p95/p99, CPU, RSS/peak memory, queue depth, worker utilization,
  relevant connections/cache hit rate, and exact runtime/dependency/OPcache settings.
  Set workload-specific tail-latency and resource ceilings from the baseline before
  evaluating the candidate; these values are not yet measured in this plan.
- Run a persistent-worker soak for at least 30 minutes after warmup, with repeated
  requests, task failures, cancellation, and idle windows. Require no progressive
  memory/queue growth and zero cross-request isolation failures. Record concrete
  RSS/latency capacity ceilings with the test environment.

Final CI must validate the exact revision being tagged, including dependency
matrix, extension lanes, release regression, packaging, and documentation build.
A passing local guard alone does not close these gates or override F1–F3.

Rollback: disable the optional bridge and return to explicit existing scope
propagation if integration behavior regresses. For a deployed release rollback,
restore the whole prior build, matching generated artifacts, dependency set, and
compatible cache namespace/configuration. Do not point CacheLayer 3 readers at
unverified 4.0 storage or preserve live request handles across runtime replacement.
