# InterMix 11.0 — major release design, audit, and implementation plan

Status: implementation plan; **11.0.0 is the selected target**. Breaking changes
are authorized for the design described here. Implementation and release are
pending. This document supersedes the former 10.2 plan.

The initial source audit was performed on 2026-09-29 against InterMix **10.1.1**,
commit `f687452b9b8d10333e7beb7d6905b479b2b8480e`. The plan was subsequently revised
against repository HEAD `82c1bfa` after the host-ownership clarification and major
release decision. The test evidence below belongs to the original audit baseline;
it is not validation of an implemented 11.0 candidate or the later workflow change.

Follow [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md)
and its agent workflow. The user's major-release decision authorizes the listed
public contract changes; it does not waive security, correctness, interoperability,
quality checks, or measured performance acceptance. Preserve useful capabilities
and remove compatibility machinery where the new contract makes it unnecessary.

## Release decision and intended outcome

Ship **11.0.0** as a coordinated migration to explicit DI configuration, immutable
runtime wiring, strict scope lifetimes, and optional host-supplied Runwire context
propagation through InterMix and downstream CacheLayer consumers.

The major is justified by the contract changes in B1–B7 below, rather than by
upgrading optional development dependencies. Do not carry 10.x compatibility
aliases, legacy input parsers, mutable-runtime mode switches, or a second legacy
DI implementation into 11.0 merely to avoid migration work. Retain the 10.x tag as
the rollback and comparison baseline. A separate urgent 10.1.x bug fix remains
possible but is outside this major-release workstream.

InterMix remains an embedded DI/invocation library with its existing independent
utilities. It runs inside a framework, application, command, or plain PHP script;
it never becomes a server, worker supervisor, or scheduler. Normal PHP execution
must work without Runwire, CacheLayer, Opis, or a framework being installed.

Keep PHP `>=8.4`, PSR-11, and PSR-6. Target optional integration tests at CacheLayer
`^4.0`, Runwire `^2.1`, and Opis `^4.5`; do not make Runwire's 64-bit requirement a
new requirement for the DI core. Pin the tested versions in release evidence and
test actual PHP 8.4/8.5 runtimes. Changing the PHP floor or package split is not a
planned part of 11.0.

Required outcomes:

1. Fix F1–F3 with regression evidence and bounded persistent-process state.
2. Give each public DI operation one explicit meaning; configuration belongs to
   the builder, and execution belongs to immutable runtime wiring.
3. Prevent root-scope leakage and container-mediated capture of request values by
   singleton construction; keep independent requests isolated during suspension.
4. Share the host's exact Runwire instances through framework → InterMix →
   CacheLayer and sibling-library paths, with one lifecycle owner.
5. Prove retained capabilities across dynamic and compiled execution, migration,
   clean packaging, provider absence, and real supported runtime configurations.
6. Demonstrate maintainability gains and acceptable sustained host RPM; do not
   equate fewer APIs or a major version number with a measured speed improvement.

## Scope and non-goals

Required: the DI/public API redesign, lifecycle defects, compilation/fallback
alignment, optional provider integration, migration documentation, consumer tests,
benchmark updates, and final CI gates in this plan.

Retain Fence, Remix, explicit signed/unsigned Closure serialization, tracing,
attributes, contextual bindings, tags, factories, and lazy construction where
consistent with the contracts below. Do not remove unrelated capabilities just to
call InterMix a pure container. Global DI lookup helpers are specifically removed;
independent `tap`, `when`, `pipe`, `measure`, and `retry` utilities remain.

Not planned: a new scheduler, parallel service construction, a framework-specific
core, remote context serialization, automatic provider discovery, mandatory
CacheLayer/Runwire dependencies, a general plugin framework, a compatibility
package, or a wholesale rewrite of proven resolution algorithms.

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

At the initial audit, live Composer registry metadata and clean local release commits agreed:

| Package | Installed at audit | Verified release target | Release commit |
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
frozen hybrid-fallback execution, plus diagnostics enabled and disabled.

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
cache reset to collect completed work. Publish the candidate process-state inventory in this plan. The consolidated
10.1 inventory below is historical; its earlier weak-ownership description missed
this strong-reference fast path.

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

Priority: **required for 11.0 integration support**.

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

## 11.0 contract decisions

These are implementation targets, not descriptions of APIs already available.
Each break has a migration rule and an acceptance test. Retained APIs keep their
parameter names unless a row below explicitly changes their contract. Freeze the
complete symbol/signature manifest before the first release candidate; a later
change to this design requires updating the migration table and its tests.

### B1 — Configuration belongs to ContainerBuilder

`ContainerBuilder` is the sole public registration/configuration owner. Remove
runtime registration through `Container`, managers, `ArrayAccess`, magic property
writes, and public repository/resolver mutation accessors. Move the existing
registration algorithms into their cohesive builder-owned implementation; do not
create one wrapper class per operation.

- `new ContainerBuilder()` configures definitions, providers, attributes,
  contextual bindings, environment, lifecycle hooks, cache policy, and diagnostics.
- `build(): Container` freezes the graph and returns a dynamic runtime.
- `compile(string $path, bool $strict = false): array` freezes the same graph and
  generates a versioned artifact plus a compilation report. Strict compilation
  rejects unsupported definitions; hybrid compilation reports frozen fallback
  requirements explicitly.
- `production(string $path): ProductionContainer` loads against that frozen graph.
  `productionPrevalidated(string $path, string $digest): ProductionContainer`
  remains an explicit trusted-deployment optimization.
- The first build, compile, or production-load operation validates and snapshots
  the graph before finalizing the builder. Configuration-validation failure leaves
  it mutable; after successful finalization, artifact generation/loading failure
  leaves it frozen and retryable with the same graph. Later configuration mutation
  throws before any state changes. Additional runtime construction from the same
  frozen graph is allowed, with separate singleton/scope stores. Production loading
  does not require a prior `build()` or request-time compilation.
- Reconfiguration creates a new builder/runtime and is installed by the host only
  at a safe lifecycle boundary. Remove the builder's live-runtime tracking,
  mutation listeners that deoptimize running containers, `development()` escape
  hatch, public `attachFallback()`, and runtime `deoptimize()` contracts.
- Definition metadata arrays must be snapshotted without writable aliases back to
  the builder. Immutable wiring does not mean deep-cloning supplied objects,
  resources, or Closure captures: these remain explicitly caller-owned handles.
  They may not capture request state in worker-lifetime definitions. Test and
  document this distinction rather than promising to freeze arbitrary PHP objects.

Public `ServiceProviderInterface::register()` now takes `ContainerBuilder`.
`builder->import(ServiceProviderInterface $provider)` accepts a supplied provider
instance; bootstrap owns any provider constructor dependencies. Attribute resolver
callbacks receive the runtime contract from B3, never a mutable builder. Environment
selection happens before freeze, not during requests. Contextual binding fluent
configuration returns the builder, with explicit class/value/factory/reference
terminal operations instead of ambiguous `give(mixed)` dispatch.

Acceptance: mutation attempts after finalization leave every existing runtime
unchanged; validation, compilation, and loading failures obey the state rules above; separate built runtimes have separate owned stores; an intentionally
supplied literal object retains its documented identity; providers cannot mutate
runtime wiring. Include named-argument and extension-interface migration tests.

### B2 — Explicit definition kinds and identifiers

Replace overloaded `bind(mixed)` and the overlapping lifetime/factory shortcuts
with one explicit builder operation per definition kind:

| Planned builder operation | Meaning |
| --- | --- |
| `value(string $id, mixed $value)` | Return the exact literal value, including a Closure, callable-looking array, class-name string, false, or null; never invoke/reinterpret it |
| `autowire(string $id, string $class, array $arguments = [], LifetimeEnum $lifetime = LifetimeEnum::Singleton, array $tags = [], array $properties = [])` | Construct the declared class with injected dependencies and explicit parameter/property overrides |
| `factory(string $id, Closure\|FactoryDefinition $factory, LifetimeEnum $lifetime = LifetimeEnum::Singleton, array $tags = [])` | Execute an explicit factory; Closure receives one `RuntimeContainerInterface`, not inferred arbitrary factory parameters; reuse existing exportable recipes |
| `alias(string $id, string $target)` | Resolve the target with its lifetime and identity; an alias has no separate singleton/scoped cache |
| `input(string $id)` | Declare a required-on-use scoped input supplied by the host at scope entry; never construct or persist it |

All registration operations return the builder. Keep `LifetimeEnum` and the three
existing lifetimes. Factory/autowire default to Singleton as today; select other
lifetimes explicitly. Values have literal container-lifetime semantics. Aliases
follow their target, including transient freshness and scoped errors, through the
entire chain. Reject alias cycles before serving when statically determinable.

Identifiers are nonempty strings at public DI boundaries. Typed string parameters
follow PHP's caller-side strictness; do not claim to detect integer coercion that
PHP performs before entry from weak callers. Remove magic/Stringable dispatch.
For ID-keyed PHP arrays, normalize integer keys to their string representation
because PHP itself converts canonical numeric-string keys; this is not positional
registration. Validate the resulting IDs and test `"0"` as a valid identifier.
Reject duplicate registration instead of silently replacing a definition. For an
intentional override, use builder-only `unbind(string $id): self` before registering
the replacement, while configuration is still mutable. Unbinding a missing ID is
idempotent. A failed replacement cannot affect an already built runtime because
its builder is frozen; do not add a registration transaction framework.

Autowiring injects registered dependencies into declared classes. `get()` no
longer discovers and registers arbitrary undeclared class names. Declare the
service graph through providers before build, use PHP defaults where applicable,
and fail clearly for unresolved required dependencies. `make()` is the explicit
operation for constructing an otherwise unregistered concrete root class; its
injected dependencies still use the registered graph. This closes an unbounded
runtime-discovery path and makes build validation and compilation predictable.

Constructor arguments accept either positional keys or parameter-name keys, not
mixed modes; reject unknown/duplicate arguments. Define variadic spreading,
defaults, union/intersection types, and `Inject` resolution once and share the
rules with invocation/compilation. Retain existing precise compound-type behavior
where unambiguous, and report unresolved ambiguity rather than guessing.

Retain `FactoryDefinition`'s explicit positional recipes and top-level
`ServiceReference` arguments; change `resolve()` to accept
`RuntimeContainerInterface` so recipes work across both implementations. A literal
string never becomes a reference. Recognized recipe/reference metadata is lowered
explicitly by the compiler, not serialized as an arbitrary object. Do not silently
extend the existing recipe format to nested references or named arguments.

Acceptance: strings matching IDs/classes/functions and callable arrays remain
literal under `value()`; factory closures execute only under `factory()`; alias
identity/lifetimes agree in every runtime; duplicates and invalid identifiers fail
without partial configuration; no autoload scanning or service construction occurs
merely by including the package.

### B3 — Retrieval, construction, and invocation are separate

Expose one canonical execution vocabulary on both runtime implementations:

| Runtime operation | Required behavior |
| --- | --- |
| `get(string $id): mixed` | Resolve a declared entry using its lifetime; no registered/default method execution or implicit Closure deserialization |
| `has(string $id): bool` | Describe graph membership without constructing services; true does not promise construction success or an active required scope |
| `make(string $class, array $arguments = []): object` | Construct a fresh concrete root from the class and supplied arguments, independent of any service-ID recipe/lifetime; class attributes and builder-wide construction policy apply; dependencies retain their configured lifetimes |
| `invoke(callable $callable, array $arguments = []): mixed` | Invoke a native PHP callable, injecting missing arguments; preserve its exact result, including null/false; never cache invocation results implicitly |
| `tagged(string $tag): iterable` | Lazily yield service ID => resolved value in deterministic registration order, not resolver closures; materialize explicitly when an eager array is wanted |

PSR-11 error semantics must distinguish an absent requested ID from a declared
entry that cannot be built. Only the former raises `NotFoundExceptionInterface`.
For an ID where `has()` is true, missing dependencies/alias targets/required inputs
raise `ContainerExceptionInterface` without implementing `NotFoundExceptionInterface`;
wrap a nested NotFound error with its original cause. Include user factories that
throw a NotFound error in these tests. Scope failures do not change graph membership.

Tagged iteration resolves each value at consumption time and obeys ordinary
lifetime/captive-dependency guards. An iterator created inside a scope must verify
that its originating scope is still live and attached on the consuming carrier;
it must not silently switch to another request or extend scope ownership. Child
consumers explicitly attach the handle. Do not retain request graphs after scope
close through an iterator's bookkeeping. Iteration outside any scope still rejects
Scoped/input entries. Document native PHP numeric-string key normalization when
materializing tagged results into arrays.

Remove `call()`, `getReturn()`, `resolveNow()`, `parseCallable()`, overloaded
method arguments to `make()`, and the separate public `Invoker` facade. Remove
`Class@method` shorthand and treating a class string as an implicit `__invoke`
request. Native callable forms remain valid, including function strings and real
static-method callables. Invoke an instance method explicitly with
`$runtime->invoke([$runtime->get('mailer'), 'send'], $arguments)`.

Configured default methods and registration of method results as part of `get()`
are removed. Express a service producing a method result as an explicit factory,
or call it through `invoke()` each time. Preserve intentional construction hooks:
`onResolving`/`onResolved` configure lifecycle callbacks at build time; any object
initialization method they call is explicit and covered by that hook's contract.
Parameter/property attributes remain supported; method attributes execute only
when the corresponding callable is explicitly invoked, never as implicit service
retrieval. `AttributeResolution::Unresolved` stays distinct from null.

Add **one** `Infocyph\InterMix\DI\RuntimeContainerInterface` extending PSR-11,
implemented by dynamic `Container` and compiled `ProductionContainer`. Include
B3's operations and B4's structured scope operations. This interface is justified
by two real implementations and the framework/provider boundary; do not create
matching interfaces for every internal planner or manager. The Runwire bridge,
factory callbacks, attribute resolvers, and retained `TaggedPipeline` accept this
contract. Remove public manager navigation/proxies and DI `ArrayAccess`/magic
access. Keep planners/resolvers internal and do not add a new runtime facade just
to forward the same calls.

`TaggedPipeline` retains its existing explicit pipeline capability and handler
rules; adapt its container dependency and tag traversal to the new contract.
Diagnostics remain opt-in and bounded; graph validation/export and preload
creation belong to the builder, while runtime trace access must not expose wiring
mutation. Injection-disabled calls migrate to ordinary PHP callable invocation;
remove the DI engine's public generic/direct execution mode.

Acceptance: behavioral tests define all result/error distinctions, native callable
forms, argument errors, attributes, tags, hooks, and PSR-11 exceptions. Run the
same contract cases on dynamic, compiled, and frozen hybrid runtimes. Migrated
10.x tests retain their meaningful assertions; remove a legacy assertion only
when the migration manifest explicitly identifies the changed contract.

### B4 — Strict, structured scope lifetimes

Use the existing opaque, process-local `ScopeContext` as the borrowed handle.
The public runtime scope operations are:

- `withinScope(string $scope, callable $callback, array $instances = []): mixed`
  opens one owned frame, invokes the callback with the runtime, and closes its
  owned frames in `finally`.
- `captureScopeContext(): ScopeContext` captures the current active frame without
  transferring ownership or extending its valid lifetime after owner close.
- `withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed`
  attaches a borrowed frame on the current carrier, runs the callback, then
  detaches it; it never closes the borrowed owner frame.
- `resetCurrentExecutionScope(): void` is a host recovery boundary for current-
  carrier owned frames/attachments only. It cannot close another carrier's scope.

Remove public `enterScope()`/`leaveScope()` and direct scope-name switching;
framework middleware/jobs wrap execution in the callback API. Preserve internal
low-level frame operations needed by these structured owners. Nested labels must
not duplicate an active ancestor label; independent requests may reuse the same
label because frame identity is separate from the label. Each owned nested frame
starts with its own seeds and scoped-instance store; it does not inherit the
parent's seeds implicitly. Attaching a captured frame shares that exact store.
Exiting either operation restores the previous carrier attachment. Returning a
Generator, Fiber, or other lazy result does not extend the callback's scope; later
work must execute within a valid explicit scope.

Resolving a Scoped definition or input without an active frame throws
`ContainerException`; the root is not a hidden request lifetime. This applies to
aliases, autowired dependencies, attributes, compiled slots, factory calls, and
scope seeds. Seed keys must identify declared Scoped entries or inputs; reject
attempts to override Singleton/Transient entries or aliases. A missing required
input fails when resolved, not simply because another unrelated service is used.

Reject container-mediated resolution of a Scoped/input value anywhere in the
active construction ancestry of a Singleton, including through a Transient or an
alias. Validate statically visible captive dependencies at build time and enforce
this at runtime for factories/hooks. Perform the ancestry check before returning
already resolved scoped values or seeds, including compiled fast paths: warming a
request value first must not let a later Singleton capture it. The rule does not
claim to inspect arbitrary objects or Closure captures supplied by application code. Callers needing
request-aware work resolve it inside that request instead of storing its state in
a long-lived singleton.

Cycle ancestry is carrier-local. Construction claims are owned by the actual
lifetime store: independent scopes may construct the same ID concurrently; one
shared scope and one container-wide singleton cannot produce competing instances.
Use explicit fail-fast contention errors for another carrier's in-progress
shared/singleton construction; distinguish them from real same-carrier cycles.
Do not introduce scheduler-dependent waiting or duplicate singleton factories.
Release claims on success, exception, cancellation, and deadline failure.

Close only when attached children have settled/detached. Returning with live child
attachments is a lifecycle error, not permission to destroy their shared store or
wait on a scheduler owned by the host. The host must settle children and complete
recovery; P0 must specify and test the recovery state transitions, including hook
execution exactly once and rejection of new attachments during recovery.
Scope cleanup invokes
all applicable leave hooks once, releases owned references even when a hook throws,
and never closes an injected connection merely because it was supplied as a seed.
A work failure without cleanup errors propagates unchanged. Cleanup failure raises
`ScopeCleanupException` (a `ContainerException`) with the original work Throwable,
if any, as `previous`, plus `cleanupFailures` and `cleanupFailureCount`. Retain at
most the first 32 cleanup Throwables and count all failures; continue cleanup even
when the retained-error limit is reached. If work succeeded, use the first cleanup
failure as `previous`. This one new exception type is justified by the distinct
compound-failure contract. Cancellation-aware hosts must inspect the original
cause when cleanup also fails; cover that migration explicitly. No static error
registry or retained trace of a successfully completed request is allowed.

Acceptance: inactive-scope rejection, captive-dependency rejection, shared child
identity, independent requests, true cycles, singleton contention, nested scopes,
seed validation, stale/cross-container handles, hook errors, cancellation, and
idle-worker collection. No new request-global static registry.

### B5 — Explicit container ownership

Remove `Container::instance()`, its alias registry/constants, `Container::unset()`,
shared `Invoker` state, and the DI lookup functions `container()`, `resolve()`,
`direct()`. Framework/application bootstrap owns concrete runtime instances and
passes them only at composition/integration boundaries. Domain services receive
their actual dependencies rather than a general service locator.

Container aliases cease to be ownership identifiers. Keep an explicit application
namespace and release generation for external definition caching (B7). Multiple
runtime instances in one process have independent singleton/scope stores even when
they intentionally share a cache pool or Runwire worker context. Fence and MacroMix
registries are independent utility configuration, not replacement DI registries.

Acceptance: plain-script use requires only builder → runtime; two hosts/containers
in one process never share DI state implicitly; Composer loading creates no global
container; independent utility helpers remain usable without a DI instance.

### B6 — Frozen compiled and dynamic execution

Keep the existing dynamic and generated production implementations where their
measured responsibilities justify separation. Share parameter, lifetime, cache-
admission, and scope policy in the smallest cohesive owners. Do not force all
compiled hot paths through reflective runtime dispatch to obtain cosmetic parity.

Hybrid execution may use reflection for declared unsupported compilation islands,
but its fallback is built from the same frozen graph. It cannot mutate definitions
or become an escape hatch to a live development container. Strict compilation
lists every unsupported definition and fails before artifact publication; hybrid
mode records exactly which entries need frozen fallback resources. Missing fallback
resources fail activation, never silently omit a service.

Increment generated artifact ABI and include InterMix major/ABI, PHP major/minor,
normalized graph/environment identity, and required fallback metadata in the
manifest. Reject 10.x/stale/mismatched artifacts before inclusion via the manifest
boundary. Regenerate artifacts during build; do not implement an in-process 10.x
artifact converter. Prevalidated loading still requires trusted immutable metadata.
Graph identity covers normalized declarative metadata; arbitrary Closure captures,
objects, and resources cannot have a portable automatic content fingerprint.
Require an explicit host build/release identity for graphs containing opaque
fallback resources, validate their declared requirements before activation, and
change that identity when their wiring changes. Do not hash serialized arbitrary
objects or promise detection of changes inside caller-owned handles.

Publish complete versioned build directories atomically; a generated PHP file and
its sidecar must activate together. Preserve old active artifacts on staging or
validation failure. No request-time compilation, directory scanning, live artifact
replacement, or serialization of request contexts/connections. OPcache and preload
validation must use the actual deployment paths and PHP version.

Acceptance: retained feature parity, explicit strict/hybrid failures, immutable
fallbacks, graph/scope identity across compiled-to-dynamic edges, ABI rejection,
failed staging, stale manifests, classmap-authoritative packaging, and release
activation/rollback. Remove obsolete deoptimization tests only by replacing them
with frozen-wiring mutation-rejection and replacement-runtime tests.

### B7 — Explicit, bounded external definition caching

PSR-6 remains optional configuration via
`builder->definitionCache(CacheItemPoolInterface $pool, string $namespace,
string $generation, bool $failOpen = true)`. Namespace/generation must be nonempty
and explicitly supplied; they are configuration identities, never request IDs.
Use a new InterMix 11 key-format/version discriminator and PSR-6-safe bounded
keys derived from namespace, generation, and definition ID. The host must change
generation whenever wiring or stable output semantics change; sharing a namespace
and generation explicitly declares cache equivalence, not automatic graph matching.
A pool shared across other libraries does not grant permission to reuse their keys
or clear their namespace.

External persistence becomes **per-definition opt-in** through builder-only
`cacheDefinition(string $id)`, limited to stable Singleton factory results that
pass safe scalar/null/array admission. Autowiring always returns an object and is
therefore ineligible. Alias caching belongs to its target; Scoped, Transient,
scoped input, and literal-value definitions cannot be marked.
Literal definitions already have their supplied value and need no external read.
Reject invalid eligibility during build. Existing automatic caching migrations
must explicitly identify safe stable outputs; do not automatically mark every
scalar-producing factory, since it may return secrets or caller-specific data.

Bound value traversal with an initial depth limit of 64 and a total visited-value
budget of 100,000 per admission attempt. Exceeding either or encountering cycles,
objects, resources, or closures makes a value non-persistable without changing the
valid in-process result. Validate cached hits as well as candidate writes; reject
invalid record/version/admission data rather than returning it as a service value.
Apply equivalent traversal bounds before exporting recipe/property/argument values,
while recognizing explicit `FactoryDefinition`/`ServiceReference` metadata as B2
requires. Cached service values still never admit those objects. A recipe requiring
unsupported data must produce an actionable build error or explicitly reported
hybrid path. Do not mutate input references or serialize arbitrary objects merely
to detect recursive data.

Keep strict/fail-open backend-error semantics, real null-hit detection, bulk
warmup, generation separation, and no implicit whole-pool clear/retry. Limits are
proposed 11.0 defaults to validate with boundary and performance tests; tighten or
adjust only with documented bounded behavior before API freeze, never weaken them
to make a failing resource-exhaustion regression pass.

Acceptance: safe opt-in values hit across intended runtimes/releases; tenant/app
namespaces differ; new 11.x keys cannot hit 10.x records; malformed/deep/cyclic
values cannot exhaust the process; cache unavailability does not change a resolved
service under fail-open policy. Provider payload policy remains independently
owned by the application.

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
6. Preserve generic PSR-6 behavior and verify an independent conforming provider
   as well as the failure-contract fake. CacheLayer 4.0 is the supported concrete
   integration target; a conforming 3.x pool may still work via PSR-6, but 3.x
   provider-specific integration is not a required 11.0 compatibility lane.
7. Follow B7's explicit cache eligibility and major-version namespace isolation.
   A shared Runwire request context does not imply shared DI value-cache identity.

## Runwire 2.1 design

### Boundary and ownership

Add one cohesive optional integration entry point,
`Infocyph\InterMix\Integration\Runwire\RunwireIntegration`, constructed with a
specific `RuntimeContainerInterface`. Its separate existence is justified by the
provider boundary and request/task lifecycle. Reuse the B4 structured scope APIs
and existing ownership mechanisms; do not add a parallel DI container or a generic
runtime abstraction family. Context services are declared scoped inputs during
bootstrap and seeded at execution entry, never registered by mutating a runtime.

Runwire 2.1 exposes concrete `RuntimeContext`, `RequestContext`, `CoroutineScope`,
`TaskLocal`, and `RuntimeCapability` APIs. Inspection found no public universal
ambient current-runtime/current-scope accessor to use. Therefore **automatic means
capability selection after the framework shares its actual context**, not runtime
discovery or construction by InterMix.

| Boundary | Proposed behavior |
| --- | --- |
| Worker bootstrap | Bind the host's actual `RuntimeContext` after worker/fork/generation creation. Validate ownership once. |
| Request/task entry | Share the active `RequestContext` and optional `CoroutineScope`; validate matching runtime identity and reject completed/stale bindings. Seed scoped context services without mutating global definitions per request. |
| DI scope | Use `withinScope()` for an owned request/job scope, or `withinScopeContext()` for a supplied live handle; cleanup closes only owned scopes and detaches borrowed handles. |
| Child work | Capture the logical DI handle and propagate it using Runwire task-local snapshots plus a bridge-provided callback wrapper at the host's spawn boundary. The child explicitly attaches/detaches through existing APIs. |
| Exit | Unwind nested integration state in `finally`; detach children before owner scope close. Apply B4's explicit compound-failure/cancellation contract. |
| Worker shutdown/replacement | Release this bridge's binding and request/task references. Host coordinates child settlement, drain, replacement, and resource disposal. |

Raw `CoroutineScope::spawn()` does not execute arbitrary InterMix lifecycle hooks.
Do not promise propagation for tasks bypassing the bridge wrapper. Snapshot
inheritance must not be described as copying service objects: children share the
intended logical scope; unrelated request scopes remain isolated. Background jobs
that outlive a request must receive a new job scope, not retain its request handle.
The host must await/join attached child work before returning from the shared DI
boundary. The bridge cannot close a borrowed Runwire scope to force settlement;
unsettled attachments produce a controlled lifecycle error. Exercise host-driven
cancellation/drain recovery as well as the normal joined-child path.

### Capability selection and fallback

| Situation | InterMix behavior |
| --- | --- |
| Runwire absent or integration unused | 11.0 synchronous DI and explicit scope behavior; no Runwire initialization, autoload scanning, workers, or listeners |
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
that the bridge resolves against its active scope. Existing helper arguments need
not change because the major's required breaks are in DI; avoid an unrelated
retry API redesign. With no bridge delay, preserve synchronous use.
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
and active `CoroutineScope` object identities at each execution boundary. A child
or nested deadline scope may differ from its parent's scope; forward the host's
actual active scope for that callback, not a stale parent scope. Do not clone
contexts, reconstruct runtime instances, or give each library a competing scheduler. Each library keeps
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
support alone does not establish downstream CacheLayer isolation. If CacheLayer
4.0 cannot satisfy a required carrier scenario, coordinate a downstream correction
and test its released version, or explicitly exclude that integration mode before
API freeze. Ordinary PSR-6 use remains available; unsafe context sharing must not
silently fall back to a shared root request context.

These rules apply to other participating libraries too. Reuse the existing
provider integration and explicit host lifecycle rather than creating separate
binding registries, reference counters, or wrappers in every library.

Development target: `infocyph/runwire:^2.1`; add an optional Composer suggestion
and documented supported range. Manual InterMix scope propagation remains
runtime-neutral. Runwire 1.x/2.0 bridge compatibility is not advertised or required
for 11.0, and no version-switching compatibility layer should be added for it.

## Migration contract: 10.1.1 → 11.0

Publish `docs/upgrade-11.0.rst`, `docs/release-11.0.rst`, updated README/quickstart,
and executable migration fixtures with the implementation. Until then, examples
below describe the target design and must not be presented as working 10.x code.
No compatibility shim is included in the 11.0 runtime.

| 10.x usage | 11.0 migration | Required proof |
| --- | --- | --- |
| `new Container()` followed by registration | Configure `ContainerBuilder`, then `build()` | Frozen runtime works in a plain PHP script |
| `Container::instance($alias)` / `unset()` / shared Invoker | Pass an application-owned runtime instance; release it at its host lifecycle boundary | No static cross-container service state |
| `container()`, `resolve()`, `direct()` | Explicit `get()` / `make()` / `invoke()` or native PHP call | Helper absence documented; unrelated helpers retained |
| `bind()`, `singleton()`, `scoped()`, `transient()`, `bindFactory()`, pending factory chains | `value()`, `autowire()`, `factory()`, or `alias()` with explicit lifetime | Literal strings/closures are never misclassified |
| `definitions()` / `registration()` / `options()` mutations | Corresponding explicit builder operation before freeze | No live manager/repository escape hatch |
| Re-register to replace a provider binding | Explicit builder `unbind($id)`, then register the chosen definition before freeze | Duplicate mistakes fail; no active runtime is changed |
| `$container[$id]`, magic property access, invoking the container | Explicit `get($id)`, `has($id)`, and builder registration | No magic dispatch; typed-call and numeric array-key rules documented |
| `get(UnregisteredClass::class)` | Register that class and its dependencies, or use `make()` for an unregistered root | No runtime definition discovery |
| `call()` / `resolveNow()` / `Invoker::invoke()` / `Class@method` | Native callable passed to runtime `invoke()` | Object resolution and method invocation are explicit |
| `make($class, $method)` | `make($class, $arguments)` followed by `invoke([$object, $method], $arguments)` | Fresh root object and fresh method invocation |
| Registered/default method + `getReturn()` | Explicit callable invocation or factory producing the intended value | No hidden method result caching |
| Alias with independent lifetime | Put lifetime on the target; use an explicit factory if distinct behavior is required | Alias-to-scoped/transient behavior correct |
| Scoped resolution outside a request/job | Wrap it in `withinScope()` or change the definition to a genuinely process-safe lifetime | No hidden root-scope object retention |
| `enterScope()` / `leaveScope()` pairs | `withinScope()` callback; capture/attach only for intentional child propagation | Success/failure/cancellation cleanup |
| Singleton captures scoped/request input through DI | Resolve request-aware work inside the request; inject process-safe collaborators into the singleton | Captive dependency fails consistently |
| Request object registered by mutating the container | Declare `input()` once and seed it at scope entry | Concurrent requests cannot overwrite definitions |
| `ServiceProviderInterface::register(Container)` | `register(ContainerBuilder)` with explicit provider instance at import | Consumer provider fixtures compile and register |
| Attribute resolver or `FactoryDefinition::resolve()` receives concrete `Container` | Receive `RuntimeContainerInterface`; keep `Unresolved` versus null semantics | Works with dynamic and compiled runtimes |
| Mutable fallback/deoptimization | Rebuild immutable runtime at host boundary; hybrid fallback uses frozen graph | Old runtime stays unchanged until host replacement |
| Existing generated PHP/preload artifacts | Regenerate with 11.0/PHP-target ABI and deployment paths | Reject 10.x artifact before activation |
| Automatic safe-singleton external caching | Configure explicit namespace/generation and mark eligible definitions | Cold 11.x namespace, no implicit cache of arbitrary scalar outputs |
| `findByTag()` / `findByTagLazy()` | Iterate `tagged()` as ID => resolved value; remove calls to old resolver closures | Order, lifetime checks, and scope liveness preserved |
| Simultaneous work and scope-cleanup failure | Inspect `ScopeCleanupException` cause and bounded cleanup failures; work-only failures remain unchanged | Original cancellation/application cause and cleanup failure count preserved |
| Synchronous/manual Runwire integration | Use 11.0 scopes directly or enable the 2.1 bridge and host-owned forwarding | Same contexts through sibling libraries; absence fallback works |

Retained construction features need explicit builder equivalents: constructor and
property overrides, contextual class/value/factory/reference bindings, attribute
resolver registration, environment-specific metadata, lifecycle hooks, tags,
validation, graph export, tracing configuration, cache configuration, and preload.
The API inventory must map every currently documented public symbol to retained,
changed, internalized, or removed status, with its exact signature and named
parameter changes. A missing migration mapping blocks API freeze; it is not
permission to silently drop a capability. `onMissing` callbacks that register
services at runtime are removed: migrate discovery to provider/bootstrap
registration and deferred instantiation to explicit factories.

Planned plain-script example (no Runwire or CacheLayer required):

```php
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

// RequestLogger is an application class; dependencies are explicit.
$builder = new ContainerBuilder();
$builder->input('request.id');
$builder->factory(
    'logger',
    static fn(RuntimeContainerInterface $runtime): RequestLogger =>
        new RequestLogger($runtime->get('request.id')),
    lifetime: LifetimeEnum::Scoped,
);
$runtime = $builder->build();

$result = $runtime->withinScope(
    'request',
    static fn(RuntimeContainerInterface $runtime): mixed =>
        $runtime->invoke([$runtime->get('logger'), 'format'], ['message' => 'hello']),
    ['request.id' => 'request-1'],
);
```

Migration execution order:

1. Inventory application/provider/attribute extension call sites and named
   arguments, including direct use of public-looking internal managers/resolvers.
2. Move registration to bootstrap builders, make definitions explicit, and replace
   globals/magic invocation in consumer fixtures.
3. Wrap host requests/jobs in structured scopes and declare/seed their inputs.
4. Regenerate artifacts, install the compatible dependency set, and select a new
   cache namespace/generation; keep CacheLayer's own 4.0 storage migration separate.
5. Bind actual host runtime instances after worker creation, enable transitive
   forwarding once, and verify teardown with multiple consumer libraries.
6. Run consumer fixtures and representative workloads before changing production
   traffic. Roll back by switching complete releases, not by hot-swapping graphs.

## Implementation tracker

Updated: 2026-09-29

This tracker is the authoritative execution state for this plan. Every batch is
implemented, QA'd, committed, and then advanced; a later batch does not begin
until the preceding batch's required QA and plan evidence are recorded.

| Batch | Package | Status | Implementation / evidence |
| --- | --- | --- | --- |
| 1 | P0 — contract and baseline | **In progress** | PR #138 opened; 10.1.1 baseline and public API disposition frozen below; branch starts from `f687452` and plan branch head `a3bb1e1` |
| 2 | P1 — demonstrated defects | Pending | F1–F3 regressions and bounded fixes |
| 3 | P2 — builder and definitions | Pending | B1/B2/B7 |
| 4 | P3 — runtime and scope contract | Pending | B3/B4/B5 |
| 5 | P4 — compiled graph | Pending | B6 |
| 6 | P5 — provider boundaries | Pending | CacheLayer 4.0 / Runwire 2.1 |
| 7 | P6 — migration and consumers | Pending | Documentation and executable consumer migrations |
| 8 | P7 — measured acceptance | Pending | Benchmarks, host workloads, soak |
| 9 | P8 — release candidate | Pending | Exact-SHA CI, packaging and release evidence |

### Batch 1 / P0 frozen baseline

P0 uses InterMix **10.1.1** commit
`f687452b9b8d10333e7beb7d6905b479b2b8480e` as the immutable behavioral and
performance comparison baseline. The implementation branch began P0 at
`a3bb1e15e4c8849987d24be866575aedbf260261`; PR #138 is kept open throughout
the staged implementation so pull-request workflows exercise each batch.

Historical audit evidence remains historical: PHP 8.5.4 CLI NTS, Composer 2.10.3,
399 tests / 2,286 assertions, 28 duplicate groups / 1,072 lines / 5.45%, 77
Deptrac-uncovered dependencies, and the released-source CacheLayer 4.0 / Runwire
2.1 probes recorded earlier in this plan. These results are not re-labelled as
11.0 branch QA. Batch QA requires fresh evidence tied to the batch commit.

The current dependency baseline is PHP `>=8.4`, PSR Cache `^3.0`, PSR
Container `^2.0`; development currently targets CacheLayer `^3.2.0`, Runwire
`^1.0`, Opis `^4.5`, and mutable PHPForge `dev-main@dev`. P5 is responsible
for moving the optional integration targets to CacheLayer 4.0 and Runwire 2.1.

### P0 public API disposition

The table below freezes the required 10.1.1-to-11.0 disposition before P2 changes
public contracts. Exact signatures and named-parameter compatibility must be
verified against implementation as each row is migrated.

| 10.1.1 surface | 11.0 disposition |
| --- | --- |
| `ContainerBuilder` | Retain as sole configuration owner; add frozen `build()`; remove live-runtime mutation/deoptimization model and `development()` escape hatch |
| `Container` | Retain as dynamic runtime implementing new `RuntimeContainerInterface`; remove registration/configuration/global ownership APIs |
| `ProductionContainer` | Retain as generated runtime implementing the same runtime contract; frozen hybrid fallback only |
| `Container::instance()`, alias constants/registry, `unset()` | Remove |
| DI `ArrayAccess`, magic/proxy writes and global DI lookup helpers | Remove |
| `bind()`, `bindFactory()`, `singleton()`, `scoped()`, `transient()`, pending factory chains | Replace with explicit builder `value()`, `autowire()`, `factory()`, `alias()`, `input()` |
| `unbind()` | Retain builder-only before freeze |
| `definitions()`, `registration()`, `options()` public manager navigation | Internalize/remove from runtime; expose cohesive builder operations instead |
| `ServiceProviderInterface::register(Container)` | Change to `register(ContainerBuilder)`; provider instance supplied explicitly |
| `FactoryDefinition` / `ServiceReference` | Retain; resolve through `RuntimeContainerInterface`; bounded export validation |
| `get()`, `has()` | Retain with strict declared-entry PSR-11 semantics |
| `make(string, string|bool)` | Change to fresh-root `make(string, array): object` |
| `call()`, `getReturn()`, `resolveNow()`, `parseCallable()`, public `Invoker` | Remove; replace callable execution with `invoke(callable, array)` |
| configured/default method execution during retrieval | Remove |
| `findByTag()`, `findByTagLazy()`, current `tagged()` resolver-closure semantics | Consolidate to lazy `tagged()` yielding ID => resolved value |
| `TaggedPipeline` | Retain, adapted to `RuntimeContainerInterface` and new tag semantics |
| `enterScope()` / `leaveScope()` | Remove publicly |
| `withinScope()`, `captureScopeContext()`, scope attach/detach machinery | Consolidate into B4 `withinScope()`, `captureScopeContext()`, `withinScopeContext()`, `resetCurrentExecutionScope()` |
| `ScopeContext` | Retain opaque process-local borrowed handle |
| root scoped lifetime | Remove; Scoped/input resolution requires an active owned/attached frame |
| runtime `onMissing` registration | Remove; discovery moves to bootstrap/provider registration |
| resolving/resolved/scope-leave hooks | Retain as builder-time lifecycle configuration |
| attributes/contextual bindings/tags/environment/constructor+property overrides | Retain with explicit builder equivalents |
| `compileTo()`, `useCompiled()`, `usePrevalidated()` mutable runtime compilation | Remove from runtime; builder owns compile/load and frozen artifact lifecycle |
| `compile()`, `production()`, `productionPrevalidated()` | Retain on builder with B1/B6 freeze semantics and new ABI |
| definition cache manager mutation | Replace with explicit builder cache configuration and per-definition opt-in from B7 |
| `attributeRegistry()`, repository/resolver mutation accessors | Internalize; expose only builder configuration / bounded runtime diagnostics |
| `debug()`, tracing/graph diagnostics | Retain capability but separate builder graph validation/export from bounded runtime trace access |
| Fence, Remix, closure serialization, non-DI helpers | Retain independently unless a later package records a specific incompatibility |

### P0 representative consumer migrations

The implementation must keep executable fixtures for these composition patterns:

1. Plain PHP: builder configuration → frozen dynamic runtime → explicit
   `withinScope()` → `get()/make()/invoke()`.
2. Framework request/job: host-owned runtime, declared scoped inputs, captured
   child scope handle, deterministic teardown.
3. Generated deployment: same frozen graph → compiled artifact → production
   runtime, with explicit hybrid fallback requirements and stale-ABI rejection.
4. Provider extension: supplied provider instance registers into the builder;
   factories and attributes depend on `RuntimeContainerInterface`, never mutable
   container configuration.
5. Cache consumer: generic PSR-6 pool, explicit namespace/generation and
   per-definition eligibility; no CacheLayer classes required by core.
6. Compound optional integration: host → InterMix → CacheLayer and host → sibling
   library → CacheLayer share the host's exact Runwire runtime/request/task
   identities while retaining one host-designated lifecycle owner.
7. Provider-absence install: production authoritative autoload with no CacheLayer,
   Runwire, Opis, Swoole, or framework installed still supports normal DI use.

P0 does not authorize implementation of those contracts; it freezes what later
batches must prove and prevents silent capability loss.

## Work packages, dependencies, and completion criteria

All implementation work is pending. The source files named here are existing
owners to inspect/change, not a requirement to preserve their current class count.
Avoid unrelated cleanup and new abstraction families.

| Package | Depends on | Scope / principal owners | Completion evidence |
| --- | --- | --- | --- |
| P0: contract and baseline | This plan | Public API inventory; current tests/benchmarks; composer/workflows; consumer fixture inventory | Every public contract has a disposition; exact baseline environment and behavioral expectations saved |
| P1: demonstrated defects | P0 | `DefinitionResolver`, `ClassResolver`, carrier/scope stores, recursive admission/exportability callers | F1–F3 regressions fail on 10.1.1; fixes pass in isolation without loosening tests; bounded repro subprocesses |
| P2: builder and definitions | P0, P1 | `ContainerBuilder`, `Repository`, `DefinitionGraph`, registration/options/definition managers, contextual bindings/providers | B1/B2/B7 registration and freeze contracts implemented; explicit metadata snapshots; no manager mutation escape |
| P3: runtime and scope contract | P1, P2 | `Container`, `ProductionContainer`, invocation machinery, scope stores, attributes, helpers, TaggedPipeline | B3/B4/B5 parity and lifecycle cases pass; old parsers, globals, and aliases removed; primary/cleanup failures accounted for |
| P4: compiled graph | P2, P3 | `Build/*`, generator/loader, preload, fallback owners | B6 ABI, strict/hybrid, artifact publication, frozen fallback and build failure cases pass |
| P5: provider boundaries | P3, P4 | Optional Runwire integration, PSR-6 configuration/tests, Composer dev/suggest constraints | Real 2.1/4.0 clean-install tests, context forwarding, single lifecycle owner, and provider absence pass |
| P6: migration and consumers | P2–P5 | README, DI docs, upgrade/release guides, extension/provider fixtures, examples | Every migration row executable or covered by a contract test; no 11.0 docs advertise removed APIs |
| P7: measured acceptance | P3–P6 | Benchmarks, soak workloads, source/abstraction inventory | Equivalent-workload RPM and resource budgets pass; claimed simplifications/benefits supported by evidence |
| P8: release candidate | P0–P7 | CI, packaging, documentation build, release notes, process-state inventory | Exact revision green on required matrix; unresolved blocking gates zero before tagging |

P1 may reuse a focused fix before the larger API migration. Do not keep a second
10.x engine inside the 11.0 codebase to make that sequencing possible. Evaluate
all reported duplicate groups as their owners are changed; centralize repeated
policy across all affected callers. Retain justified generated hot-path code,
measure it, and record any remaining diagnostic dispositions without suppressions.

Required 11.0 design artifacts before RC:

- Public API and named-parameter change manifest, migration guide, and upgrade fixtures.
- Definition-kind/lifetime matrix, immutable graph/fallback contract, and error semantics.
- Process-state inventory covering surviving bounded metadata caches, weak carrier
  references, explicit utility registries, and per-runtime/per-scope stores.
- Provider support matrix and framework → InterMix → CacheLayer plus sibling-library
  lifecycle tests, with supported carrier models named explicitly.
- ABI/build identity specification, namespace cutover and whole-release rollback procedure.
- Test, clean-install, analyzer, performance, soak, and exact-SHA CI evidence.

No source implementation, Composer constraint update, workflow change, tag, or
release publication is performed by this planning task. The previously reported
399-test baseline must not be relabelled as an 11.0 acceptance run.

## Engineering-principles compliance and decision gates

The installed engineering-principles file is mandatory throughout implementation.
The authorized major removes the old BC constraint only for the explicit B1–B7
changes. It does not authorize weaker security, quality thresholds, or speculative
architecture.

| Principle | Concrete 11.0 requirement |
| --- | --- |
| Correctness/security before speed | F1–F3, scope isolation, cache admission, signature checks, and host ownership block release regardless of benchmark results |
| Highest sustainable successful RPM | Measure equivalent complete host workloads; component microbenchmarks support diagnosis only; redesign simplifications that materially regress throughput |
| Smallest coherent structure | Reuse current builders/planners/stores where possible; private methods before new single-use types; no per-operation strategy/DTO hierarchy |
| Justified public types | `RuntimeContainerInterface`: two execution implementations and consumers; `RunwireIntegration`: provider/lifecycle boundary; `ScopeCleanupException`: compound-failure contract; other new types require a recorded independent reason |
| Complexity limits | Inspect active PHPStan configuration; keep its existing limits; engineering defaults are class 80, function 12, dependency tree 120; do not raise limits or fragment code just to game them |
| Genuine interoperability | PSR-11 for DI consumers, PSR-6 for supplied cache pools; do not add another PSR/provider dependency or adapter without a concrete boundary |
| No request state in long-lived globals | No DI alias registry; weak carrier references; per-runtime/per-scope stores; downstream worker binding has one designated lifecycle owner |
| Explicit, bounded resources | Bounded validation/error retention; deterministic scope cleanup; no owned runtime loops/workers; no unbounded retries or parallel warmup |
| Quality checks remain effective | No new exclusions, suppressions, baselines, disabled checks, reduced assertions, or raised detector thresholds to absorb valid findings |
| Evidence boundaries | Historical 10.1.1 tests, source overlays, host checks, provider services, runtime matrix, load/soak, and final-SHA CI remain separate claims |

Record before/after public symbol count, production class/file count, repeated
policy, hot-path call depth, autoloaded symbols, and memory as review signals.
Do not impose an arbitrary file-count reduction. A smaller API is a maintainability
outcome, not permission for an RPM regression or an unsupported feature claim.

Before P2 implementation, fix the API disposition table and verify representative
consumer migrations. Before P7 acceptance, establish environment-specific tail
latency/RSS limits and variance. Before RC, close every required support-matrix
cell. No unmeasured budget or untested integration can be silently marked passed.

## CI, packaging, and release publication changes

Update CI during implementation, not in this planning task:

1. Rename 10.1 candidate labels/artifact names in
   `.github/workflows/security-standards.yml` to 11.0 and compare against the
   immutable 10.1.1 baseline. Preserve meaningful historical comparisons separately.
2. The current harness invokes one candidate script against both autoloaders. The
   major changes those APIs: introduce version-specific setup/call adapters outside
   measured hot loops and identical scenario/output checks. Do not obtain a
   favorable result by omitting initialization hooks, dependencies, cache semantics,
   or scope cleanup from only one side. Record unsupported old/new scenarios
   separately; do not combine non-equivalent throughput figures.
3. Run actual PHP 8.4 and 8.5 with stable and lowest supported dependencies. Add
   clean consumer installs with no optional packages, each advertised optional
   integration, and authoritative production autoloading. The core/no-Runwire lane
   must not inherit Runwire's platform requirements through the test harness.
4. Exercise APCu with CLI explicitly enabled and real Swoole/OpenSwoole lanes.
   Backend services and extension presence must be asserted before related tests;
   absence-branch assertions are not provider-compatibility evidence. Add an
   independent PSR-6 provider consumer. Pin evidence to actual installed versions.
5. Add explicit compound-dependency consumer fixtures: host → InterMix → CacheLayer,
   host → second library → CacheLayer, direct CacheLayer use, and mixed paths.
   A same-process fake consumer suffices for focused ownership cases; a migrated
   framework/application fixture must also validate the real composition root.
6. Build documentation with warnings treated as failures using the repository's
   documentation workflow; execute the upgrade/quickstart examples and provider
   interface fixtures. Verify release archives omit temporary probes and dev-only
   runtime dependencies and include the intended public assets.
7. The current workflow routes tag pushes to a release workflow while skipping
   normal PHPForge/release-regression jobs on tags. Before publication, explicitly
   require all required checks for the **exact tagged commit** and its dependency/
   artifact evidence, or run the necessary gates in the release workflow itself.
   Inspect the reusable release workflow rather than assuming it enforces this.
   A prior branch run for another revision is insufficient.
8. Publish 11.0.0 only after P0–P8 acceptance, migration docs, immutable artifacts,
   namespace cutover, and rollback evidence are complete. No tagging or publication
   is authorized as part of editing this plan.

## Single-plan policy

This is the sole plan file for InterMix. The former 10.2 release plan and 10.1
process-state audit are superseded; their necessary findings, baseline identity,
commands, counts, provider limitations, and process-state evidence are preserved
here. Both older files have been removed after verifying consolidation. Maintain this document
through implementation with dated evidence tied to exact revisions, rather than
creating additional competing release plans.

Implementation guides and executable tests may live in their normal documentation
and test locations; they are not additional planning files. Keep historical and
candidate evidence separate within this document. Never replace the recorded
10.1.1 result with an unqualified claim that the 11.0 gates passed.

## Verification matrix and release gates

Functional coverage must include:

- Dynamic, generated production, and frozen hybrid-fallback containers; cold and
  warmed paths; singleton, scoped, transient, aliases, scope seeds, hooks, and null.
- Builder freeze, literal/factory distinction, provider/attribute signatures,
  native callable invocation, removed globals/legacy forms, undeclared IDs,
  strict scope/captive dependency errors, singleton contention, alias lifetimes,
  explicit cache eligibility, and 10.x artifact rejection from B1–B7.
- PSR-11 declared-entry versus missing-ID errors, including nested factory errors;
  warmed scoped/seed captive-dependency rejection; stale/cross-request tagged
  iterators; nested seed isolation; lazy work returned past scope close; numeric
  IDs; recipe reference export; failed builder finalization versus artifact I/O.
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

- Establish baseline on 10.1.1 before changing hot carrier/resolution paths. Use
  the version-specific benchmark setup described above to preserve equivalent
  workload/output semantics across the API break; retain 10.0.4 historical evidence
  separately where useful.
- Run at least five alternating baseline/candidate pairs on the same stable
  environment; separate cold startup, first call, warm resolution, cache hit/miss,
  independent requests, and shared child scopes. Confirm equivalent results. Run
  each baseline and candidate warm host workload for at least five minutes after
  warmup at concurrency
  1, 8, 32, and the measured saturation region; record any environment-driven
  changes to that curve before comparing candidates.
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
  evaluating the candidate; these values are not yet measured in this plan. Require
  zero wrong/cross-request outputs, zero unexpected failures/timeouts in correctness
  workloads, and zero progressive queue growth. Count expected injected failures
  separately; never include them in successful RPM.
- Run a persistent-worker soak for at least 30 minutes after warmup, with repeated
  requests, task failures, cancellation, and idle windows. Require no progressive
  memory/queue growth and zero cross-request isolation failures. Record concrete
  RSS/latency capacity ceilings with the test environment.

Final CI must validate the exact revision being tagged, including dependency
matrix, extension lanes, release regression, packaging, and documentation build.
A passing local guard alone does not close these gates or override F1–F3.

Rollback: disable the optional bridge and return to explicit existing scope
propagation using the 11.0 structured scope API if integration behavior regresses.
This does not restore removed 10.x APIs. For a deployed release rollback,
restore the whole prior build, matching generated artifacts, dependency set, and
compatible cache namespace/configuration. Do not point CacheLayer 3 readers at
unverified 4.0 storage or preserve live request handles across runtime replacement.

## Consolidated historical process-state audit and evidence

This section preserves the necessary information from the former
`intermix-10.1-process-state-audit.md`. It describes **10.1/10.1.1**, not an
implemented 11.0 runtime. The initial full-suite audit recorded above ran the
existing stress tests. F2 corrects the old audit's incomplete assertion that all
Fiber carrier references were weak.

| 10.x state | Lifetime/bound and existing cleanup | 11.0 disposition |
| --- | --- | --- |
| `Container::$instances` | Application alias registry; cardinality follows created aliases; `Container::unset()` removes an alias | Remove via B5; host owns runtime instances |
| `Container::parseCallable()` descriptor cache | Process metadata cache bounded to 512 entries with oldest-entry eviction | Remove with legacy callable grammar in B3; preserve any measured native-callable metadata cache only with a bound |
| `Invoker::$sharedInstance` | Process singleton attached to the shared DI alias | Remove via B3/B5 |
| `ReflectionResource::$reflectionCache` | Reflection metadata buckets, default limit 2,048; `clearCache()` and `setCacheLimit()`; oldest-entry eviction | Retain bounded metadata only; define/test every limit mode before claiming a global bound |
| `ReflectionResource::$closureReflectionCache` | WeakMap keyed by Closure; explicit `clearCache()` | Verify collection on supported PHP versions; the audit's 100-Closure payload probe retained 0/100 on PHP 8.5.4 |
| Fence capability caches | Class/extension existence caches, each bounded to 256 | Retain capability metadata; no request values |
| Fence instance/limit registry | Explicit consumer configuration; consumer limit, `clearInstances()`, `reset()` | Retain independent utility contract; never use request/tenant/job IDs as process registry keys |
| MacroMix macro/Closure metadata maps | Class/application registration lifetime; `removeMacro()` and optional mutation locking | Retain bootstrap-owned configuration; no captured request principals in long-lived registrations |
| `ExecutionContext` carrier tokens | WeakMaps for object identity plus **strong `$lastFiber` fast path**; coroutine numeric-ID fallback requires deterministic cleanup | Fix F2; weak carrier ownership, stable live tokens, GC evidence after final idle request |
| Dynamic `ExecutionScopeStore` and compiled `ProductionScopeStore` | Container-owned scope data; current-carrier reset/detach, owner-close/attachment guards | Reuse proven ownership mechanisms with B4 strict scopes and carrier-local resolution ancestry |
| Optional CacheLayer Runwire binding | Provider-owned process-static worker context plus Fiber-local/root execution binding | One host-designated owner; exact instance sharing; no borrower release; no unsupported native-coroutine root sharing |

The former audit's continuing rule remains: every process-wide mutable state item
must be a bounded metadata cache, a weak cache, an explicit configuration registry,
or resettable runtime metadata. Request/job/session/tenant values belong in owned
execution scopes, never in a process registry. A request-scoped object referenced
by a cache value or a fast-path field still counts as retained request state even
if its key is weak or its label says metadata.

Existing `tests/Container/StructuredScopeStressTest.php` coverage includes:

- A warmup followed by four structured-scope churn windows, with process-memory
  growth and sample spread constrained to 1 MiB.
- Dynamic execution-scope storage returning to null after each measured window.
- WeakReference collection of captured `ScopeContext` and logical state after
  owner close and garbage collection.
- Reuse of one stable application alias across 256 framework-style request scopes
  without registry growth, followed by exact registry restoration on `unset()`.

These are useful 10.x regressions, not proof against every retention path: the
last-Fiber probe exposed a gap despite those tests passing. For 11.0, preserve the
scope churn/collection assertions, replace removed alias-registry assertions with
explicit runtime-ownership/collection tests, and add F2's terminated-Fiber return
graph and idle-worker cases. Keep dynamic/compiled/hybrid, failure, cancellation,
and deadline coverage. Do not rename or delete assertions solely to hide failures.

Additional bounded audit probes and observations:

| Probe | Method | Observed 10.1.1 result |
| --- | --- | --- |
| Independent suspended construction | Two Fibers run distinct request scopes and the same scoped factory; first suspends before returning | Second fails with `Circular dependency for definition 'scoped'.`; F1 contains the public-API reproducer |
| Last-Fiber retention | Fiber calls `ExecutionContext::id()`, returns an object, terminates; drop Fiber reference; collect cycles; query main carrier; inspect WeakReferences | Both completed Fiber and returned object remain reachable |
| Closure reflection collection control | Reflect 100 temporary closures capturing distinct objects; drop locals and collect cycles | 0/100 captured objects retained; no closure-cache leak demonstrated on that host |
| Recursive cache admission | Public factory returns an array referencing itself with definition caching enabled; child process uses `timeout 3` and `php -d memory_limit=32M` | Fatal memory exhaustion, exit 255; F3 contains the public-API reproducer |

The temporary source-overlay compatibility runner prepended PSR-4 loaders for
`Infocyph\Runwire\` and `Infocyph\CacheLayer\` pointing at clean sibling release
sources, retained InterMix's installed autoloader for the remaining packages, and
printed reflection source paths to verify the classes actually used. It then ran
the existing suite with an explicit Pest configuration. This explains the scope
of the 399/2,286 and 16/77 results; it was not a clean dependency install and is not
an acceptable substitute for P5/P8 packaging gates. The APCu-specific run enabled
`apc.enable_cli=1`; the ordinary host run did not.

During implementation, append actual command/configuration, revision, dependency
versions, environment, result, and remaining limitations for each P0–P8 gate here.
Required future evidence includes clean Composer matrices, migrated consumer
fixtures, native coroutine modes, independent PSR-6 provider, production-equivalent
RPM, a persistent-worker soak, and exact-final-revision CI. None of these are
claimed complete by this planning update.

Plan cross-check on 2026-09-29: reviewed B1–B7 against the installed PSR-11
contract, current recipe/scope code, the CacheLayer integration source, and the
engineering principles. Document checks passed: exactly one planning file, valid
local Markdown links, balanced fences, no trailing whitespace, and `php -l` on
all three extracted PHP examples. `git diff --check` passed for tracked changes.
These are documentation checks; proposed APIs were not executed and no 11.0
implementation or release gate is closed by them.
