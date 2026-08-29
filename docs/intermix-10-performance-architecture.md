# InterMix 10 — Finalized Maximum-Performance DI Architecture

## Engineering guardrails

This architecture is governed by PHPForge's `resources/engineering-principles.md`.

Correctness, security, data integrity and stability remain non-negotiable. Sustained successful production-equivalent throughput is the primary performance metric. The design therefore keeps build/configuration work out of the production request path, keeps optional diagnostics and genuinely dynamic behavior off the hot path, and prefers simple measurable mechanisms over speculative abstractions.

InterMix remains framework-agnostic. It does not infer development or production mode from `APP_ENV`, `PHP_ENV`, framework globals or another implicit environment convention.

## Implementation status checker

This checker is authoritative for the InterMix 10 standalone implementation.

`[x]` means the item is resolved for InterMix 10: implemented and covered, or deliberately resolved by a measured architectural decision. Webrick, Foundation and InfByte validation is a downstream phase and is not an InterMix implementation blocker.

### Dynamic/runtime baseline

- [x] Cached-first singleton/scoped resolution.
- [x] Correct cached `null` singleton/scoped semantics.
- [x] Missing-service hook fast flag keeps hook traversal off the common path.
- [x] Missing-service activation preserves configured lifetime.
- [x] Contextual-binding fast flag avoids work when no contextual bindings exist.
- [x] Repository-wide property-resource fast flag avoids hierarchy scans when property work is impossible, including late-registration correctness.
- [x] WeakMap Closure reflection cache avoids unbounded identity retention.
- [x] Stable named-callable parameter/type-group plans are cached; Closure planning intentionally remains identity-sensitive rather than using an unsafe source-location cache.
- [x] Tracing/dependency-recording work is absent when tracing is disabled.
- [x] Definition registration is atomic and performs one invalidation per mutation.
- [x] Bootstrap mutation/invalidation work is minimized.
- [x] Compiled resolver activation replaces an already-materialized dynamic dispatcher.
- [x] Stale `CompiledCall` dispatchers self-heal after compiled metadata invalidation.
- [x] Ordinary development class resolution removes duplicate allocation where practical; the remaining `ClassResolution` wrapper is development-only and absent from generated production service resolution.

### InterMix 10 architecture implementation order

- [x] 1. Freeze InterMix 9 observable DI semantics with regression/parity coverage across compiled boundaries and intentional dynamic islands.
- [x] 2. Retain `perf/di-hot-path` as the optimized dynamic/development baseline.
- [x] 3. Introduce immutable normalized `DefinitionGraph` build snapshots.
- [x] 4. Introduce `ContainerBuilder` with explicit development, compile and production-loading paths.
- [x] 5. Make the builder the authoritative InterMix 10 configuration/finalization facade while reusing the optimized dynamic Container/Repository as mutable build storage rather than duplicating a second mutable graph.
- [x] 6. Finalize the development boundary as `ContainerBuilder::development(): Container`; a separate wrapper was deliberately rejected because it adds allocation/API surface without semantic or performance value.
- [x] 7. Confirm development semantics across the supported PHPForge PHP 8.4/8.5 stable and lowest matrices.
- [x] 8. Introduce compiler IR/plans for all statically representable behavior and targeted runtime-island plans for behavior that genuinely depends on runtime state.
- [x] 9. Fold deterministic environment bindings, lifetimes and tags at build time, including compound type members.
- [x] 10. Flatten safe aliases and detect alias cycles during build while preserving singleton/scoped cache barriers.
- [x] 11. Compile constructor/parameter plans for named definitions, contextual/environment bindings, defaults/null, exportable supplied values, aliases and union/intersection/DNF groups; runtime-supplied/custom strategies remain narrow dynamic paths.
- [x] 12. Specialize singleton, transient and scoped lifetimes with generated state, nested scopes and seeds including `null`.
- [x] 13. Compile deterministic contextual bindings; genuinely runtime contextual behavior remains isolated.
- [x] 14. Compile or narrowly isolate attribute/property/method behavior, including deterministic built-in `#[Inject]`, registered properties/methods, default/`CALL_ON`/`__invoke`, non-public/readonly handling and custom attribute resolvers.
- [x] 15. Validate static dependency cycles during compilation.
- [x] 16. Assign integer service slots and generate per-service methods.
- [x] 17. Implement the minimal generated `ProductionContainer` runtime with lazy compatibility islands.
- [x] 18. Implement generated singleton/scoped state and nested-scope seed handling.
- [x] 19. Make known internal dependency edges call generated service methods directly rather than recursively calling public string-based `get()`.
- [x] 20. Compile/specialize tags, resolving/resolved hooks and scope-leave behavior while keeping hook-free generated services free of hook dispatch work.
- [x] 21. Compile known `call()`, `getReturn()`, fresh `make()`, class-only/explicit-method `resolveNow()`, `[Class::class, 'method']` definitions and statically representable invocation parameters; arbitrary runtime callables remain compatibility islands.
- [x] 22. Support arbitrary dynamic invocation/service fallback without deoptimizing unrelated known compiled services.
- [x] 23. Keep Closure definitions and `DirectFactory` as explicit dynamic islands with compiled-ID bridges preserving singleton/scoped identity across compiled↔dynamic edges.
- [x] 24. Implement explicit production deoptimization for configuration mutation with original definitions restored and compiled singleton/scoped identity transferred.
- [x] 25. Separate compiler/diagnostic metadata into a sidecar; the hot artifact contains generated runtime execution code only.
- [x] 26. Perform atomic generation and build/deployment validation with ABI/SHA-256 metadata; prevalidated production loading avoids request-time `hash_file()` work.
- [x] 27. Finalize the constant-folding/transient-inlining decision: no additional speculative inlining pass is added without stable representative evidence of a sustained end-to-end win.
- [x] 28. Benchmark generated boundary representations. The immutable ID→slot candidate showed only a noisy nominal advantage, so the simpler generated string `match` boundary is retained until stable-environment measurements justify changing it.
- [x] 29. Keep Repository/managers/reflection resolver machinery entirely out of fully static generated artifacts; instantiate the optimized dynamic engine lazily only when a declared compatibility island is exercised.
- [x] 30. Complete development/compiled/deoptimized parity coverage across lifetimes/scopes/seeds, aliases/cycles/cache barriers, tags, environment/context and compound types, factories/closures, properties/methods/custom attributes, hooks, arbitrary classes, invocation/error surfaces, generic/injection-off mode, prevalidated loading, deoptimization and cross-island identity.
- [x] 31. Run PHPBench natively through PHPForge on PHP 8.4 and PHP 8.5 using the project `bench:intermix` script; no project `ic:*` command is overridden.
- [x] 32. Move external Webrick RPM validation to the downstream Webrick phase after InterMix standalone semantic/quality/benchmark gates are green.
- [x] 33. Finalize the public migration model: existing `Container` remains the compatible dynamic/development API, `ContainerBuilder` is the explicit InterMix 10 configuration/finalization API, and generated `ProductionContainer` is the production runtime.

### Final release gates

- [x] Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize pass across PHP 8.4/8.5 stable and lowest dependency matrices.
- [x] PHPStan and Psalm pass on PHP 8.4 and PHP 8.5.
- [x] Clean `--no-dev --classmap-authoritative` production install/platform/autoload checks pass.
- [x] Native PHPForge benchmarks pass on PHP 8.4 and PHP 8.5.
- [x] Project `composer.json` does not override PHPForge `ic:*` commands.
- [x] No temporary diagnostic workflow remains.
- [x] Fully static artifact tests verify the generated artifact contains no `Reflection`, `Repository`, `RuntimeIslandResolver`, `ParameterResolver`, `ClassResolution` or `InjectedCall` machinery.
- [x] Final InterMix-only diff/release decision is complete; downstream request-level validation is tracked separately.

## 1. Objective

InterMix 10 treats dependency injection as a two-phase system.

```text
Development/build phase
-----------------------
Configuration
    ↓
Mutable optimized dynamic Container/Repository
    ↓
Immutable DefinitionGraph snapshot when compiling
    ↓
Development execution OR compiler

Production phase
----------------
Finalized DefinitionGraph
    ↓
Static planner + renderer
    ↓
Generated runtime artifact + metadata sidecar
    ↓
ProductionContainer
    ↓
Direct generated service calls
```

Development does **not** require compilation.

Production uses a finalized generated artifact as its normal execution model and never self-compiles because an artifact is missing during a live request.

## 2. Non-negotiable functionality

InterMix 10 retains the observable capabilities required by the current library:

- PSR-11 `get()` / `has()`
- singleton, transient and scoped lifetimes
- aliases
- values/scalars/null definitions
- constructor autowiring
- method invocation/injection
- property injection
- method/property/parameter attributes
- custom attribute resolvers
- contextual bindings
- environment-specific bindings and metadata
- tags and tagged pipelines
- direct factories
- Closure definitions
- static/declarative factories
- resolving/resolved/scope-leave lifecycle hooks
- missing-service hooks
- nested scopes and seeds including `null`
- `make()`
- `call()`
- `resolveNow()`
- `getReturn()`
- callable parsing and equivalent error behavior
- validation and tracing/debugging support
- dependency graph/definition metadata in build/dev tooling
- optional definition cache support
- compiled/prevalidated deployment
- arbitrary autowirable classes
- generic/non-injection mode

The compiler changes **when** configuration work happens, not whether a valid capability is available.

## 3. Public phase model

`ContainerBuilder` is the InterMix 10 configuration/finalization surface.

Conceptual usage:

```php
$builder = ContainerBuilder::create();

$builder
    ->singleton(...)
    ->scoped(...)
    ->when(...)
    ->setEnvironment('prod');
```

Development:

```php
$container = $builder->development();
```

Production build:

```php
$report = $builder->compile('/cache/intermix.prod.php');
```

Production loading:

```php
$container = $builder->productionPrevalidated(
    '/cache/intermix.prod.php',
    $report['sha256'],
);
```

The runtime application should depend on PSR-11/the runtime contract, not on the builder.

## 4. Development runtime

The existing optimized `Container` remains the development runtime. A separate `DevelopmentContainer` wrapper is intentionally not introduced.

Development remains fully dynamic:

- reflection/autowiring available
- runtime mutation available
- dynamic bindings and attributes available
- environment mutation available
- rich tracing/debug information available
- missing-service activation dynamic
- arbitrary callable resolution dynamic
- compiler optional

All hot-path improvements from the InterMix 9 audit remain part of this runtime rather than being discarded by the major-release architecture.

## 5. Immutable build snapshot

`DefinitionGraph` isolates the production compiler from the live mutable Repository.

The snapshot carries the build-time information required to compile deterministic behavior, including definitions, effective environment metadata, class/Closure resources, contextual bindings, registered attributes and feature/options state.

Compiler passes operate on this snapshot instead of consulting live mutable runtime state repeatedly.

## 6. Production runtime boundary

`ProductionContainer` is intentionally small.

A fully static generated artifact owns only what request-time execution needs:

- generated service dispatch
- per-service methods
- singleton state
- scope state
- seed mapping
- compiled tag indexes
- compiled lifecycle behavior
- compiled invocation/return dispatch where representable
- optional lazy compatibility bridge

It does not instantiate Repository, DefinitionManager, OptionsManager, RegistrationManager, InvocationManager, Reflection-based resolvers or diagnostic graphs on the known compiled path.

## 7. Service IDs and generated slots

Known services receive integer slots during compilation.

```text
0 → Foo
1 → Bar
2 → DatabaseConnection
3 → RequestContext
```

External PSR-11 calls still use string IDs. The generated boundary maps the external ID to the known service method.

Internal compiled edges do not repeat that string lookup. They directly call the target generated method, e.g. `$this->s1()`.

This removes repeated definition, lifetime, context, environment and resolver selection from the compiled dependency graph.

## 8. Generated service methods

Generated recipes are deliberately close to hand-written PHP.

Example shape:

```php
private function s14(): Logger
{
    return $this->v14 ??= new Logger(
        $this->s3(),
    );
}
```

The compiler already knows constructor order, dependencies, lifetime, contextual/environment target, deterministic attributes/properties/method work and lifecycle requirements.

## 9. Lifetime specialization

Lifetime checks disappear from known generated edges.

- transient services return a fresh generated construction
- singleton services use generated singleton state
- scoped services use the active `ScopeState`

Nullable/mixed values preserve the distinction between unresolved and resolved-to-`null` where required.

## 10. Scope runtime

`ScopeState` contains only runtime scope information:

- name
- parent
- resolved slot values
- slot seeds
- raw fallback seeds where needed for dynamic islands

Scope IDs are mapped to compiled slots once when possible. Nested scope restoration and `null` seeds retain parity with development behavior.

The lazy fallback is synchronized to production scope state if it is materialized after a scope has already been entered.

## 11. Constructor and parameter planning

Reflection parameters are transformed into build-time plans.

Static plans cover:

- named class/interface dependencies
- named parameter definitions
- defaults and nullable fallback
- supplied exportable values
- contextual targets
- environment targets
- aliases
- `self` / `parent` normalization
- union/intersection/DNF type groups when deterministically resolvable
- deterministic built-in injection attributes

Runtime supplied parameters, arbitrary custom strategies and identity-sensitive callable behavior remain narrow compatibility paths instead of forcing unrelated services dynamic.

## 12. Alias compilation

Safe transient alias chains are flattened at build time.

Alias cycles are rejected during compilation.

Singleton and scoped aliases retain cache/identity barriers where flattening would change observable lifetime semantics.

## 13. Environment folding

Production environment selection is finalized at build time for deterministic bindings, lifetime metadata and tags.

The generated artifact does not repeatedly query an environment map for known services.

Development may still mutate environment dynamically. Production configuration mutation triggers explicit deoptimization rather than silently returning stale compiled assumptions.

## 14. Contextual binding compilation

Deterministic contextual bindings are selected per consumer during planning and become direct generated dependency edges.

Runtime-dependent contextual values/callables remain targeted dynamic islands.

Known unrelated services remain compiled.

## 15. Attribute compilation

Attribute handling is classified during build.

Deterministic built-in `#[Inject]` behavior is compiled directly where representable.

Registered custom attribute resolvers retain full functionality through targeted runtime-island plans when their behavior depends on runtime state.

A class with no applicable attribute work executes no attribute-engine path in its generated service method.

## 16. Property injection

Property work is planned once.

Generated direct assignments are used where semantics permit. Reflection/runtime-island handling is restricted to cases requiring runtime resolver behavior or reflection-only access rules.

Classes with no property work execute no PropertyResolver path.

The development runtime additionally uses a repository-wide property-resource flag so unrelated class resolution does not repeatedly scan hierarchies.

## 17. Method invocation/injection

Registered methods, default methods, `CALL_ON`, `__invoke` and deterministic injected method parameters are compiled where statically representable.

Invocation metadata is produced only where the API needs method return semantics, rather than allocating a generic wrapper for ordinary constructor resolution.

Arbitrary/custom runtime callables continue through the compatibility invoker.

## 18. Tags

Tag membership is finalized into generated indexes for compiled services, including environment-specific tag folding.

`findByTag()` and lazy tag traversal resolve compiled service IDs without rebuilding tag metadata from Repository state.

## 19. Lifecycle hooks

Resolving, resolved and scope-leave semantics remain available.

The compiler records hook presence so hook-free generated services execute no hook loop. Services with hook behavior invoke only the relevant planned callbacks.

Hook callbacks/Closures remain runtime values; InterMix does not implicitly serialize them into the artifact.

## 20. Missing-service behavior

Known generated IDs do not check missing-service hooks.

Only unknown-ID/dynamic compatibility paths perform missing-service activation.

This keeps missing-service flexibility available without taxing normal compiled lookups.

## 21. Closure definitions and `DirectFactory`

Closure definitions remain supported.

They are intentionally classified as runtime definitions because arbitrary Closure identity and captured runtime state are not safely representable as ordinary generated PHP definitions.

`DirectFactory` remains the low-overhead explicit path for runtime Closure factories and is not reflected/autowired as a normal callable.

Closure serialization remains **explicit only**. Opis serialization/HMAC is never silently inserted into normal DI resolution or compiled production startup.

## 22. Dynamic islands

Not every dynamic operation deoptimizes the whole container.

Cold/runtime-dependent operations such as arbitrary Closure invocation, unregistered autowirable classes, arbitrary callables, custom runtime attributes and dynamic definitions may materialize a lazy compatibility engine.

Compiled-ID bridges route references back into `ProductionContainer`, so compiled singleton/scoped identity remains authoritative across compiled↔dynamic edges.

## 23. Production deoptimization

Configuration mutation that invalidates compiled assumptions performs explicit deoptimization.

Deoptimization preserves correctness by:

- restoring original bridged definitions
- transferring already-resolved compiled singleton identity
- transferring active scoped values/seeds
- preserving environment/configuration state
- preserving lifecycle behavior

After deoptimization, correctness takes priority over compiled speed.

## 24. `get()`, `has()`, `make()`, `call()`, `resolveNow()` and `getReturn()`

Known service retrieval uses generated dispatch.

Known statically representable invocation paths are also specialized:

- compiled definition calls
- `getReturn()`
- fresh class `make()`
- zero-/statically-supplied class resolution
- explicit known class method invocation
- generated `[Class::class, 'method']` definitions

Arbitrary parameterized runtime callables preserve development error and resolution semantics through the compatibility path.

## 25. Artifact generation and validation

Production compilation is explicit and atomic.

The compiler emits the runtime artifact plus metadata/manifest information containing ABI and digest data.

Build/deployment validates:

- invalid IDs
- alias cycles
- impossible parameters
- invalid factory/method targets
- unsupported recipes
- missing deterministic dependencies
- contextual/environment inconsistencies
- circular dependencies
- artifact ABI/digest compatibility

Production never performs expensive compilation because an artifact is absent on a live request.

## 26. Prevalidated loading

Normal verified loading can validate an artifact digest.

Deployment systems that already validated the artifact may use prevalidated loading with the expected digest, eliminating request-time `hash_file()` work.

This separates artifact verification from artifact execution.

## 27. Metadata sidecar

Diagnostic/compiler information does not belong in the hot runtime class.

The sidecar may contain:

- original service IDs
- dependency graph
- compiled/skipped reasons
- environment metadata
- compiler report
- source/debug information
- artifact ABI and digest

Tooling/debugging can load it on demand without burdening normal request bootstrap.

## 28. Tracing and diagnostics

Tracing-off production artifacts execute no tracing stack/dependency-recording work.

Development retains rich tracing.

Production diagnostics can materialize metadata/dynamic tooling only when explicitly requested.

## 29. CacheLayer and definition caching

CacheLayer remains optional.

Development may continue using PSR-6 definition caching where useful.

Production must not perform PSR-6 lookups for values already known and embedded in the compiled artifact.

No CacheLayer dependency is introduced merely to support normal generated resolution.

## 30. Lazy/eager behavior

Services are naturally lazy until requested.

Production compilation/finalization is separate from service instantiation.

If eager warming is desired, it should be an explicit boot/deployment action rather than hidden runtime invalidation behavior.

## 31. Representation benchmark decision

The final comparison benchmark evaluated the current generated string `match` boundary against an immutable ID→slot array candidate.

Representative CI measurements:

| Representation | PHP 8.4 | PHP 8.5 |
| --- | ---: | ---: |
| immutable ID→slot candidate | 0.352 µs | 0.336 µs |
| generated static string-match graph | 0.367 µs | 0.356 µs |

The PHP 8.4 candidate result carried ±29.64% variance, and the nominal gap on the non-stable runners is not strong enough to justify additional representation complexity.

The production generator therefore retains the simpler string-match boundary. The candidate remains in the benchmark suite as evidence and can be revisited under a stable benchmark environment.

## 32. Constant folding / transient inlining decision

The compiler already folds deterministic values, aliases, environment/context choices and direct dependency edges into generated recipes.

A separate aggressive transient-constructor inlining pass is **not** added for InterMix 10 because it would duplicate generated code/opcodes and there is no stable representative evidence that it improves sustained application throughput.

This is a completed architectural decision, not unfinished work. Revisit only when a benchmark proves a meaningful end-to-end gain without semantic or memory regression.

## 33. Production hot-artifact purity

A fully static generated artifact is tested to contain none of the following machinery:

- `Reflection`
- `Repository`
- `RuntimeIslandResolver`
- `ParameterResolver`
- `ClassResolution`
- `InjectedCall`

These remain available only in build/development or lazily materialized compatibility paths.

## 34. Semantic parity strategy

Parity coverage spans development, compiled production and deoptimized production for:

- transient/singleton/scoped lifetimes
- nested scopes and seeds including `null`
- aliases, cache barriers and cycles
- environment/context folding
- union/intersection/DNF resolution
- tags
- factories and Closures
- deterministic and custom attributes
- property and method injection
- lifecycle hooks
- arbitrary class fallback
- compiled/fresh invocation
- callable parser/error surfaces
- generic/injection-off behavior
- prevalidated loading
- configuration deoptimization
- compiled↔dynamic singleton/scoped identity

The goal is observable semantic equivalence, not identical internal machinery.

## 35. Benchmark snapshot

Representative PHPBench results from the final standalone comparison run:

| Subject | PHP 8.4 | PHP 8.5 |
| --- | ---: | ---: |
| Native transient graph | 0.203 µs | 0.194 µs |
| Generated static transient graph | 0.367 µs | 0.356 µs |
| Immutable-map candidate | 0.352 µs | 0.336 µs |
| Dynamic transient graph | 20.031 µs | 20.112 µs |
| Generated static warm singleton | 0.117 µs | 0.118 µs |
| Dynamic warm singleton | 0.186 µs | 0.174 µs |

These microbenchmarks prove the architecture removes the generic dynamic resolver from known production edges. They do **not** replace downstream request-level RPM validation.

## 36. CI / quality contract

The finalized implementation is validated through PHPForge reusable workflows across PHP 8.4 and PHP 8.5, including stable and lowest dependency sets.

Required gates are green:

- Pest
- Pint
- PHPCS
- PHPProbe
- Deptrac
- Rector
- Composer Normalize
- PHPStan
- Psalm
- clean production install/autoload/platform checks
- representative PHPBench execution

InterMix does not redefine or override PHPForge-owned `ic:*` commands.

## 37. Migration model

InterMix 10 keeps the migration surface explicit:

- existing `Container` → compatible dynamic/development runtime
- `ContainerBuilder` → configuration, validation, compilation and production finalization
- generated `ProductionContainer` → production request/runtime execution

Applications can continue using the dynamic container where runtime mutation is required, while performance-sensitive production deployments can opt into generated artifacts without losing InterMix functionality.

## 38. Downstream handoff

InterMix standalone implementation is complete.

The next performance audit stages are intentionally outside this repository's completion gate:

```text
InterMix 10
    ↓
Webrick
    ↓
Foundation
    ↓
InfByte integration / end-to-end RPM validation
```

Webrick should consume the finalized InterMix production path before its request-level benchmark is used to judge the combined stack. Foundation follows after Webrick, then InfByte integration validates the complete bootstrap/request lifecycle.

## 39. Final completion statement

InterMix 10's performance architecture is finalized when all of the following are true:

- development remains compiler-free and fully dynamic
- production uses an explicit finalized artifact
- known production dependency edges execute generated direct calls
- dynamic features remain available through narrow islands/deoptimization
- singleton/scoped identity survives compiled↔dynamic boundaries
- no implicit Closure serialization is introduced
- CacheLayer remains optional
- no framework-specific environment auto-selection is introduced
- production never self-compiles during a live request
- the static artifact is free of Repository/Reflection/resolver machinery
- semantic/quality/benchmark gates are green on supported PHP versions
- downstream Webrick/Foundation/InfByte benchmarks are tracked as the next phase rather than as unchecked InterMix work

All InterMix-specific conditions above are now satisfied on `perf/di-hot-path`.