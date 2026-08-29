# InterMix 10 — Maximum-Performance DI Architecture Draft

## Engineering guardrails

This design is governed by PHPForge's `resources/engineering-principles.md`.

The implementation must remain framework-agnostic, correctness/security/data-integrity/stability are non-negotiable, and sustained successful production-equivalent RPM is the primary performance objective. Prefer simple, explicit and measurable solutions; avoid speculative abstractions and unnecessary runtime layers. Optional diagnostics and dynamic machinery must remain off the common hot path, bootstrap must be deterministic and minimal, and every architectural performance decision must be validated with representative measurement rather than syntax-level assumptions.

## Implementation status checker

This is the authoritative release checklist for this draft. Update it whenever implementation changes. InterMix 10 is not considered complete merely because CI is green; completion means the relevant architecture items below are either `[x]` or intentionally deferred with an explicit reason.

Legend:

- `[x]` complete and covered by implementation/tests.
- `[-]` partially implemented; the remaining gap is stated inline.
- `[ ]` not implemented or not yet validated.

### Dynamic/runtime baseline

- [x] Cached-first singleton/scoped resolution in `InvocationManager::get()`.
- [x] Correct cached `null` singleton/scoped semantics.
- [x] Missing-service hook fast flag keeps hook traversal off the common path.
- [x] Missing-service activation preserves scoped/transient lifetime after registration.
- [x] Contextual-binding fast flag avoids work when no contextual bindings exist.
- [x] Property resolution exits early when no property work exists.
- [x] WeakMap Closure reflection cache avoids unbounded identity retention.
- [-] Parameter/type-group plans are cached for stable named callables; Closure plan caching is deliberately bypassed until identity-safe reuse is proven.
- [x] Tracing stacks/dependency recording are skipped when tracing is disabled.
- [x] Definition registration is atomic and performs one invalidation.
- [x] Fresh container bootstrap avoids unnecessary mutation/invalidation paths.
- [x] Compiled resolver activation replaces an already-materialized dynamic resolver.
- [-] Ordinary class resolution removed one duplicate `ClassResolution` allocation, but the base wrapper still exists in the dynamic resolver path.
- [x] Repository-wide property-resource fast flag keeps hierarchy scans off classes when property attributes are disabled and no property registrations exist; unrelated constructor/method resources do not activate the property path, and late property registration is covered.
- [x] Compiled-resolver invalidation self-heals an already-materialized `CompiledCall` dispatcher back to the dynamic resolver when compiled metadata is invalidated.

### InterMix 10 architecture implementation order

- [-] 1. Freeze InterMix 9 semantic behavior with regression tests. Existing regression coverage is strong and compiled/dynamic/deoptimized parity coverage is broad, but the complete feature matrix is still pending.
- [x] 2. Treat `perf/di-hot-path` as the optimized dynamic baseline.
- [x] 3. Introduce an internal normalized definition graph (`DefinitionGraph`).
- [x] 4. Introduce `ContainerBuilder` with explicit development, compile and production-loading paths.
- [-] 5. Move mutation/configuration ownership into the builder. The builder is now the intended configuration facade, but it still delegates mutable storage to the optimized development `Container`/Repository.
- [-] 6. Build an explicit `DevelopmentContainer` boundary on the normalized graph. `ContainerBuilder::development()` provides an explicit development boundary, but it currently returns the optimized `Container` rather than a distinct `DevelopmentContainer` implementation.
- [x] 7. Confirm the current semantic test suite against development mode. Pest passes across the current PHPForge PHP 8.4/8.5 stable/lowest matrix; the dedicated DevelopmentContainer class decision remains tracked separately in item 6.
- [-] 8. Introduce compiler IR/plans only where required. Static parameter, compound-type, property, method, factory, invocation, feature, runtime planning and rendering are separated, but the production IR does not yet represent every InterMix feature.
- [x] 9. Add production environment folding for deterministic environment bindings, including compound constructor type members.
- [x] 10. Add alias flattening and build-time alias-cycle handling in the static production compiler, preserving singleton/scoped alias cache barriers while flattening safe transient chains.
- [-] 11. Add constructor/parameter planning. Named dependencies, deterministic contextual/environment targets, supplied exportable values, defaults/null, union/intersection/DNF type groups, statically provable aliases and deterministic built-in method-invocation parameter `#[Inject]` targets are compiled; constructor attributes, registered custom/runtime attributes, variadics and genuinely dynamic strategies remain fallback/skipped.
- [x] 12. Add lifetime planning and specialized generated runtime handling for singleton, transient and scoped services, including scope state/seeds.
- [x] 13. Add contextual-binding planning for deterministic service/class bindings; dynamic contextual bindings are explicitly kept in fallback islands, including compound type cases.
- [-] 14. Add attribute/property/method planning to generated recipes. Registered public property assignments, built-in property `#[Inject]`, deterministic built-in method/parameter `#[Inject]` cases, registered/default/`CALL_ON`/`__invoke` public methods, deterministic supplied parameters and compound DI parameters are compiled. Unregistered irrelevant attributes no longer force fallback; registered custom resolvers, typed method-level `#[Inject]` precedence cases and reflection-only non-public/readonly cases remain dynamic.
- [x] 15. Add static dependency-cycle validation for the current static candidate.
- [x] 16. Generate service slots/per-service methods with direct internal dependency calls for supported static graphs.
- [x] 17. Implement an explicit minimal `ProductionContainer` with generated runtime loading and lazy dynamic fallback.
- [x] 18. Implement generated singleton/scoped runtime state with nested scope state and slot-based seeds.
- [x] 19. Implement direct compiled internal dependency calls for supported static graphs; internal edges do not recurse through public string `get()`.
- [-] 20. Compile tags and lifecycle/scope-leave hooks. Tag indexes are compiled; resolving/resolved-hook services are explicitly isolated to the dynamic runtime by `ContainerBuilder`, while direct compiled hooks and scope-leave hook plans remain pending.
- [-] 21. Implement compiled `call()` / `make()` / invocation paths. Known compiled definition calls, `getReturn()`, fresh class `make()`, zero-supplied `resolveNow()`, generated `[Class::class, 'method']` definitions and fresh explicit method invocation are compiled when statically representable. Parameterized runtime calls, arbitrary/custom callables and reflection-only invocation cases remain fallback.
- [x] 22. Implement arbitrary dynamic invocation/service fallback without deoptimizing known compiled services; parity tests confirm compiled singleton identity remains authoritative across the fallback boundary.
- [x] 23. Implement runtime Closure/dynamic-definition islands and `DirectFactory` integration in the production runtime. Dynamic fallback bridges route compiled IDs back to `ProductionContainer`, preserving singleton/scoped identity across compiled↔dynamic edges.
- [x] 24. Implement production deoptimization for true configuration mutation while preserving singleton/scope identity. Original bridged definitions are restored, compiled singleton/scoped state is transferred, and builder mutation automatically deoptimizes active production runtimes.
- [x] 25. Separate diagnostic/compiler metadata from the hot runtime artifact using a metadata sidecar containing ABI/hash/environment/compiled/skipped information.
- [x] 26. Move artifact validation fully to build/deployment. Runtime generation is atomic; manifests carry ABI/SHA-256 metadata; normal load can verify the artifact, while explicit prevalidated production loading accepts the deployment digest and avoids request-time `hash_file()` work.
- [ ] 27. Add safe constant folding/transient inlining only after parity and measurements prove it safe/useful.
- [-] 28. Benchmark alternative generated representations. Dynamic/static/native comparison exists, but the representation matrix is not complete and the reusable benchmark gate is not yet active.
- [-] 29. Remove obsolete Repository/manager/resolver machinery from the production runtime. The normal compiled path no longer constructs those objects; the lazy dynamic compatibility island intentionally still uses the optimized development engine.
- [-] 30. Run the full development/compiled/deoptimized semantic parity matrix. Current coverage includes lifetimes/scopes/seeds, tags, aliases and alias cache barriers/cycles, environment/context folding, union/intersection/DNF resolution, implicit alias targets, dynamic factories/closures, registered/built-in property injection, deterministic built-in method/parameter `#[Inject]`, attribute-island classification, lifecycle-hook islands, arbitrary class fallback, compiled/fresh invocation paths, prevalidated production loading, injection-off semantic guarding, deoptimization state transfer, property-resource activation and cross-island identity; registered custom attribute execution, direct compiled hooks, generic-mode static recipes and a few dynamic callable/error surfaces remain pending.
- [-] 31. Run the PHPBench matrix. Benchmarks exist; native PHPForge benchmark execution is currently skipped and must be enabled without adding a project-level `ic:*` override.
- [ ] 32. Run external Webrick benchmark after the InterMix standalone gates are green.
- [ ] 33. Finalize the InterMix 10 public migration API only after the runtime architecture and downstream validation are complete.

### Current release gates

- [x] Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize pass across PHP 8.4/8.5 with stable and lowest supported dependency sets on the current implementation batch.
- [x] PHPStan and Psalm analysis jobs pass on PHP 8.4 and PHP 8.5 on the current implementation batch.
- [x] Clean production install (`--no-dev --classmap-authoritative`) and platform/autoload checks pass.
- [ ] Native PHPForge benchmark execution must run and pass; it is currently skipped by the reusable workflow because no benchmark input is selected.
- [x] Project `composer.json` does not override PHPForge `ic:*` commands.
- [x] Temporary PHPStan diagnostic workflow was removed after normal PHPForge analysis confirmed green.
- [-] Security & Standards quality/analyzer/clean-install gates are green on the current head; the benchmark gate remains intentionally outstanding.
- [ ] Final diff review: no accidental public contract loss, no benchmark-only production code, and no temporary CI/debug artifacts.

## 1. Objective

InterMix 10 should treat dependency injection as a two-phase system:

**Build/configuration phase**
→ definitions, aliases, contextual bindings, attributes, hooks, environment, lifetimes, tags, factories, validation and compilation.

**Runtime phase**
→ resolve services, invoke callables, manage scopes and execute already-established lifecycle semantics.

The primary goal is to remove every piece of configuration/build machinery from the normal production request path while retaining the full InterMix feature set.

Two first-class execution variants are required:

```text
Development
-----------
Configuration
    ↓
Mutable definition graph
    ↓
Reflection/autowiring runtime
    ↓
Immediate execution

Production
----------
Configuration
    ↓
Finalize + validate
    ↓
Compiler
    ↓
Generated runtime artifact
    ↓
Tiny compiled runtime
    ↓
Immediate execution
```

Development must **not require compilation**.

Production should use compilation as its normal execution model.

Do not silently select a mode from `APP_ENV`, `PHP_ENV`, or another framework-specific variable. InterMix is framework-agnostic; the consuming application must select the execution path explicitly.

## 2. Non-Negotiable Requirements

InterMix 10 must prioritize performance without removing functionality.

The following capabilities must remain available:

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
- environment-specific bindings
- environment-specific lifetime/tag metadata
- tags and tagged pipelines
- direct factories
- normal Closure definitions
- static/declarative factories
- lifecycle hooks
- missing-service hooks
- nested scopes
- scope seeds, including `null`
- `make()`
- `call()`
- `resolveNow()`
- `getReturn()`
- callable parsing
- validation
- tracing/debugging
- dependency graph inspection
- definition caching
- preloading support
- compiled/prevalidated deployment
- arbitrary autowirable classes
- generic/non-injection mode

The production compiler may change **when** configuration is performed, but must not make a currently valid capability impossible.

## 3. Core Architecture

InterMix 10 should split the present monolithic container responsibilities into three conceptual layers.

```text
ContainerBuilder
      │
      ├────────────── DevelopmentContainer
      │                    │
      │                    └── DynamicResolver
      │                         Reflection
      │                         Mutable definitions
      │                         Runtime invalidation
      │
      └── Compiler
             │
             ├── runtime.php
             └── metadata sidecar
                    │
                    ▼
             ProductionContainer
                    │
                    ├── generated service methods
                    ├── singleton state
                    ├── scope state
                    └── lazy dynamic fallback
```

Keep the number of public classes small. Most compiler/runtime implementation classes should be `@internal`.

The architectural split is more important than the exact public class names.

## 4. ContainerBuilder

`ContainerBuilder` becomes the authoritative configuration surface.

Move configuration-oriented responsibilities out of the production runtime:

- `bind`
- `singleton`
- `transient`
- `scoped`
- `alias`
- `value`
- `factory`
- `bindFactory`
- `unbind`
- contextual bindings
- environment configuration
- attribute resolver registration
- property/method registration
- lifecycle hook registration
- missing-service hook registration
- options
- definition-cache configuration
- validation
- compilation
- preload generation

The builder owns the mutable definition graph.

It may freely use associative metadata, Reflection, validation objects, compiler metadata, fingerprints, sorting, hashes, debug information and dependency graphs. None of that automatically belongs in the production runtime.

## 5. Development Variant

The development path must remain fully dynamic.

Conceptual usage:

```php
$builder = new ContainerBuilder();

$builder->singleton(...);
$builder->when(...);
$builder->onMissing(...);

$container = $builder->development();
```

No generated file is required.

Development characteristics:

- reflection enabled
- automatic class discovery enabled
- dynamic bindings enabled
- runtime mutations enabled
- attributes resolved dynamically
- environment changes allowed
- rich tracing available
- detailed validation/debug information available
- missing-service activation completely dynamic
- callable resolution dynamic
- compiler entirely optional

The existing `perf/di-hot-path` improvements become the baseline rather than being discarded:

- cached-first singleton/scoped lookup
- missing-hook fast flags
- contextual-binding fast flags
- property-work fast flags
- WeakMap closure reflection
- parameter resolution plans
- type-group plans
- tracing-off fast paths
- atomic definition mutation
- reduced bootstrap invalidation
- compiled resolver activation fixes
- null-cache correctness
- missing-service lifetime correctness

Continue optimizing the development path independently after the architectural split.

## 6. Production Variant

Production should no longer instantiate the current Repository + managers + reflection resolver graph for every request.

The compiler should generate a specialized runtime container.

Conceptually:

```php
final class GeneratedContainer extends ProductionContainer
{
    public function get(string $id): mixed
    {
        return match ($id) {
            Foo::class => $this->s0(),
            Bar::class => $this->s1(),
            'config'   => $this->s2(),
            default    => $this->fallback($id),
        };
    }

    private function s0(): Foo
    {
        return $this->foo ??= new Foo(
            $this->s1(),
        );
    }

    private function s1(): Bar
    {
        return new Bar();
    }
}
```

The generated form should be benchmark-driven, but the important principle is:

**internal compiled dependencies must not recursively call the public string-based `get()` API.**

Known dependency edges should call generated service slots/methods directly.

That removes repeated string ID lookup, lifetime lookup, `has()`, `class_exists()`, `interface_exists()`, environment lookup, definition lookup, resolver selection and metadata lookup from compiled dependency graphs.

## 7. Compile Service IDs to Slots

During compilation, assign every known service an integer slot.

```text
0 → Foo
1 → Bar
2 → DatabaseConnection
3 → RequestContext
4 → config
```

External PSR-11 calls still accept strings.

Only the boundary performs `string ID → slot/generated service method`.

Internal compiled dependency edges should already know their target slot.

Benchmark both generated `match ($id)` and immutable ID→slot arrays and retain whichever is measurably faster on supported PHP versions.

## 8. Generated Per-Service Methods

Prefer generated service methods over one giant generic resolver when benchmarks validate the approach.

```php
private function s14(): Logger
{
    return $this->logger ??= new Logger(...);
}
```

Advantages:

- direct internal calls
- lifetime known at compile time
- contextual binding already selected
- environment already selected
- attributes already planned
- constructor already known
- no generic resolver branch tree
- OPcache can optimize normal PHP code
- no Reflection

For very large graphs, benchmark generated per-service methods against chunked dispatch to avoid pathological generated class/opcode size.

## 9. Lifetime Specialization

Lifetime decisions must disappear from the known production hot path.

Do not perform `getDefinitionLifetime($id)` for every resolution.

The compiler already knows the lifetime.

Generate specialized code for transient, singleton and scoped services.

For nullable/mixed definitions, preserve the distinction between `not resolved` and `resolved to null` without forcing the common non-null object path through expensive generic existence checks.

Benchmark generated properties versus integer-indexed arrays. Do not assume `SplFixedArray` is faster.

## 10. Scoped Runtime

Create a minimal internal `ScopeState` containing only runtime state:

```text
parent
name/id
resolved values
resolved-null markers
seeds
```

Compiled scoped service methods should access the active scope directly and must not search metadata, determine lifetime, rebuild structural keys or inspect definition registries.

Convert scope seed IDs to compiled slots once at `enterScope()` whenever possible.

Nested scope restoration and null seeds must remain correct.

## 11. Remove ClassResolution from Ordinary Service Resolution

Ordinary `get()` should return the resolved value directly.

Do not allocate a `ClassResolution`-style wrapper for ordinary constructor injection.

Keep invocation result metadata only for operations that genuinely require it:

- explicit method invocation
- `getReturn()`
- APIs needing both instance and method return information

Production service construction should normally allocate only the service graph itself. Development should receive the same optimization wherever practical.

## 12. Build-Time Parameter Plans

The compiler must transform Reflection parameters into immutable execution plans.

A compiled parameter plan should already know:

```text
source
dependency/service slot
default
nullable
variadic
contextual target
attribute strategy
self/parent normalization
environment target
```

Production must not repeatedly inspect Reflection parameter/type/attribute metadata for compiled call sites.

The development resolver should also cache equivalent plans after first reflection where identity semantics allow it safely.

## 13. Constructor Graph Compilation

For known class graphs, compilation should resolve constructor, parameter order, dependency edge, contextual override, environment override, default value, nullable fallback, union/intersection strategy, property work and post-construction method work before runtime.

The generated service recipe should be as close as possible to hand-written PHP.

## 14. Safe Compile-Time Inlining

After the basic compiler is correct, add an optimization pass.

Allow inlining only where semantic equivalence is provable.

Candidates:

- pure transient leaf constructors
- constants
- immutable scalar values
- flattened aliases
- fixed environment bindings
- fixed contextual bindings
- simple static factories

Never inline across scoped lifetime, singleton identity, lifecycle hooks, dynamic factories, unresolved custom attribute behavior, missing-service behavior or observable method invocation semantics.

Every compiler optimization must have a benchmark proving its value.

## 15. Alias Flattening

Resolve alias chains during compilation.

Do not traverse `A → B → C → D` at runtime. Compile each known alias directly to the final service slot and detect alias cycles during build.

## 16. Environment Folding

Production environment selection belongs at build time.

Prefer environment-specific compiled artifacts over runtime environment branches inside every service resolution.

Environment-specific interfaces, lifetimes, tags, metadata and factories must be folded into the artifact.

Changing environment dynamically in development remains supported.

If a production application changes environment after loading a compiled artifact, transition to dynamic fallback/deoptimized mode rather than silently returning incorrect compiled services.

## 17. Contextual Binding Compilation

Contextual bindings should be resolved into each consumer's generated recipe.

No contextual-binding hash lookup is needed for known compiled edges.

## 18. Attribute Compilation

Attribute functionality must remain.

The compiler should inspect attributes during build and classify their runtime requirements.

For built-in deterministic injection attributes, compile the result directly.

For custom attribute resolvers that genuinely depend on runtime state, compile the attribute metadata/plan and invoke only the necessary resolver at runtime.

The absence of attributes must result in zero attribute-engine work for that service.

## 19. Property Injection Compilation

The compiler should know whether each class has property work.

Classes without property injection must have zero PropertyResolver involvement.

For classes with property injection, generate direct assignments where safe.

Reflection-based property traversal remains a development fallback only.

Inheritance property plans should be calculated once.

## 20. Method Injection Compilation

Registered/default method invocation should receive the same treatment.

Compile whether a method is invoked, method name, argument strategy, injected slots and supplied runtime argument positions.

Avoid creating general invocation metadata unless the caller asks for method return information.

## 21. Tags

Compile tag indexes into service-slot lists.

`findByTag()` resolves those slots and `findByTagLazy()` yields slot resolvers.

No production request should reconstruct tags from definition metadata.

Environment tag overrides must already be folded into the artifact.

## 22. Lifecycle Hooks

Keep all lifecycle hooks.

Compile hook presence per service.

Services without hooks must execute no hook-related loop and ideally no hook branch.

Services with hooks should invoke only their known hook list.

Scope-leave hooks should similarly be indexed by compiled scope metadata.

## 23. Missing-Service Hooks

Missing-service functionality remains important for dynamic systems.

It must stay entirely off the known-service production path.

Compiled IDs should never check missing hooks. Only the unknown-ID fallback should perform missing activation.

For InterMix 10, consider cleaning the callback contract so a missing hook may directly return a definition/value rather than being forced to mutate the main container during resolution. If existing mutation-style behavior is retained, it should activate dynamic fallback.

## 24. Production Dynamic Fallback / Deoptimization

Production should be aggressively immutable by default, but functionality must not disappear.

Use a lazy deoptimization model.

Normal state:

```text
Generated Production Runtime
dynamic engine = null
```

No development resolver objects are constructed.

If code performs an operation that fundamentally invalidates compiled assumptions—rebinding, environment mutation, injection option changes, attribute registration, contextual binding mutation or compiled metadata mutation—instantiate the dynamic engine lazily and transition into compatibility mode.

When deoptimizing:

- preserve already-resolved singleton identities
- preserve active scope state
- import definitions/metadata from the compiled sidecar
- preserve hooks
- preserve seeds
- preserve environment
- preserve existing observable behavior

After deoptimization, correctness is more important than compiled speed.

## 25. Dynamic Islands Without Full Deoptimization

Not every dynamic operation should force whole-container deoptimization.

Cold operations such as arbitrary one-off Closure invocation, arbitrary unregistered autowirable classes, unknown functions and runtime supplied callables can use a lazily created `DynamicInvoker`.

Known compiled services remain compiled.

## 26. Arbitrary Closures

Do not remove Closure definitions.

Do not implicitly introduce Opis serialization/HMAC into the normal production resolution path.

Classify arbitrary closures as dynamic definitions.

Production loading may accept runtime bindings for definitions that cannot safely be represented in generated PHP.

Compile-friendly definitions such as classes, values, static factories and declarative factories should require no runtime registration.

Closure serialization remains an explicit feature, never an implicit DI requirement.

## 27. DirectFactory

Retain `DirectFactory`.

A direct factory should remain the low-overhead choice for runtime Closure factories.

Do not reflect/autowire a DirectFactory Closure.

Compiled runtime must recognize it directly.

## 28. Definition Cache

Do not make CacheLayer mandatory.

Development may continue supporting the PSR-6 definition cache.

Production compiler should eliminate cache lookups for data that can be safely placed directly into the artifact.

The production hot path must never call PSR-6 merely to obtain a value already known during compilation.

## 29. Lazy Loading

The existing lazy-loading toggle should be reconsidered in the major release.

The desired InterMix 10 model is: **services are lazy unless explicitly warmed.**

Both dev and prod naturally behave this way.

If disabling lazy loading currently represents a useful observable capability, replace the ambiguous toggle with an explicit warm/eager operation during build/boot rather than retaining runtime invalidation machinery without meaningful behavior.

## 30. Locking

Production does not need repeated lock checks.

A successfully compiled container is intrinsically finalized.

Development/build mode may retain explicit locking/finalization.

## 31. Validation

Production compilation must imply strict validation.

Validate at build/deployment:

- invalid IDs
- alias cycles
- impossible constructor parameters
- invalid factory targets
- invalid method references
- unsupported static recipe
- missing required definitions
- environment mismatch
- contextual-binding mismatch
- invalid compiled dependency
- circular dependency
- artifact ABI incompatibility

Do not repeatedly validate during requests.

## 32. Artifact Validation

Separate artifact verification from artifact execution.

Hashing, fingerprint generation, registration sorting and manifest comparison belong to build, deployment validation and CI—not normal request bootstrap.

Production loading should effectively be a direct include plus minimal ABI/version sanity checks.

## 33. Generated Metadata Sidecar

Do not force diagnostic metadata into the hot runtime class.

Compiler may emit:

```text
container.runtime.php
container.metadata.php
container.preload.php
```

The runtime artifact contains only what execution needs.

Metadata sidecar may retain original service IDs, dependency graph, source locations, skipped/dynamic reasons, validation data, compiler report, tracing/debug information and definition descriptions.

Load it only when tooling/debugging needs it.

## 34. Tracing

Production compiled resolution should have zero tracing overhead when tracing is disabled.

Do not place a tracing branch inside every generated dependency edge if the artifact was compiled without instrumentation.

If runtime debugging is explicitly requested, lazily load diagnostic metadata and/or use a dynamic diagnostic resolver.

Development retains full tracing.

## 35. Repository Split

The current Repository contains both configuration and runtime state.

Split those concepts.

Build/development state may contain definitions, metadata, resources, attributes, contexts, environment maps, compiler information, validation information and invalidation generations.

Production runtime state should contain only resolved singletons, current scope, scope stack, runtime bindings, runtime hook state and optional dynamic fallback.

Do not ship a giant mutable Repository object into the normal compiled path.

## 36. Remove Manager Layers from Production Runtime

`DefinitionManager`, `OptionsManager` and `RegistrationManager` belong to build/development configuration.

A production PSR-11 lookup should not need those objects to exist.

`InvocationManager` should not sit between `ProductionContainer::get()` and generated service code.

Production should make direct calls.

## 37. Container Global Instance Registry

Move global multiton/instance-registry convenience away from the performance-critical runtime container.

The generated container should not maintain static application-wide instance maps unless explicitly requested.

If the convenience is retained, place it in a separate lightweight registry/facade.

## 38. ArrayAccess and Convenience Proxies

Do not make convenience APIs dictate runtime architecture.

If ArrayAccess and manager proxy behavior remain desirable, implement them in builder/development or an optional compatibility facade rather than forcing extra responsibilities into the compiled production container.

## 39. Callable Compilation

Registered controller/callable targets should be compiled.

Pre-resolve class, method, static/non-static status, DI parameter plan and supplied parameter positions.

The current parse/string-descriptor machinery remains only for truly dynamic calls.

## 40. `has()` Specialization

Production `has()` for compiled IDs should use the generated ID table only.

For unknown IDs, preserve broad dynamic semantics through the fallback.

Do not call `class_exists()` for every known ID.

## 41. `make()` Specialization

For known compiled classes, generate a fresh-construction recipe.

`make()` must bypass singleton/scoped identity as required by its semantics.

For unknown classes, use the lazy DynamicInvoker/reflection fallback.

## 42. `call()` / `resolveNow()` Specialization

Recognize and optimize separately:

- compiled definition ID
- compiled class method
- compiled static method
- registered function
- arbitrary Closure
- arbitrary callable

Do not route all categories through the same generic parsing/resolution machinery.

## 43. Generic / Injection-Off Mode

Retain injection-off functionality.

In development, use the direct generic resolver.

In production, compile the selected behavior.

If injection is disabled at build time, generated recipes should contain no autowiring machinery.

Changing this option at runtime may trigger dynamic deoptimization.

## 44. Circular Dependency Detection

Development requires runtime circular-resolution guards.

Production should detect all statically known cycles during compilation.

For a fully compiled graph, do not maintain runtime cycle stacks for edges proven safe at build time.

Keep guards only around dynamic/fallback resolution.

## 45. Compiler Optimization Passes

Implement compilation in explicit stages:

```text
Definitions
    ↓
Normalization
    ↓
Environment folding
    ↓
Alias flattening
    ↓
Context resolution
    ↓
Reflection graph analysis
    ↓
Parameter planning
    ↓
Lifetime planning
    ↓
Attribute/property/method planning
    ↓
Cycle validation
    ↓
Dynamic-island classification
    ↓
Dead metadata elimination
    ↓
Safe constant folding
    ↓
Safe transient inlining
    ↓
Code generation
```

Keep optimization passes internal and testable, but do not create abstractions solely to mirror the diagram. Prefer the smallest implementation that keeps each measured transformation clear.

## 46. OPcache-Oriented Generation

The production artifact should be normal deterministic PHP designed to benefit from OPcache.

Prefer generated classes, constants, direct method calls, direct `new`, direct static factory calls and immutable arrays/constants where useful.

Avoid runtime eval, source parsing, Closure reconstruction, reflection, dynamic code generation, serialization and metadata hydration.

Generated file output must remain deterministic and atomic.

## 47. Bootstrap Allocation Budget

A production container should instantiate as little as possible.

Normal compiled boot should ideally allocate only:

- generated container
- root scope state if needed
- runtime-binding table if dynamic definitions exist

It should not eagerly allocate Repository, DefinitionManager, RegistrationManager, OptionsManager, InvocationManager, DefinitionResolver, ParameterResolver, PropertyResolver, ClassResolver, AttributeRegistry, DebugTracer, compiler objects or Reflection objects.

Those objects may appear lazily when a dynamic/debug capability is actually used.

## 48. Fast Path Contract

For a known compiled service in a normal production artifact, the resolution path must contain none of the following unless required by that exact service:

```text
Reflection
class_exists()
interface_exists()
method_exists()
attribute scanning
environment lookup
contextual-binding lookup
tag lookup
definition lookup
lifetime lookup
PSR-6 lookup
hash generation
artifact fingerprinting
sorting
service-ID normalization
missing-hook traversal
debug tracing
dependency graph recording
lock checks
cache invalidation
manager proxies
resolver switching
ClassResolution allocation
Opis serialization
```

This is an architectural review rule, not a license to change semantics.

## 49. Dev/Prod Semantic Parity Tests

Every feature needs parity testing across:

```text
development runtime
production compiled runtime
production compiled runtime after dynamic fallback
```

Given the same definitions and operation, observable results must match.

Test especially singleton identity, transient freshness, scopes, null values/seeds, environment overrides, contextual bindings, tags, lifecycle hooks, missing hooks, attributes, property injection, method injection, union/intersection/DNF resolution, arbitrary Closure calls, DirectFactory, static factories, aliases, `make`, `call`, `getReturn`, errors/exceptions and circular dependencies.

Compiler optimizations are not allowed to change semantics.

## 50. Development Performance Suite

Keep benchmarks for dynamic behavior:

- new empty development container
- 10/50/100 definitions
- first singleton
- warm singleton
- null singleton
- first scoped
- warm scoped
- transient
- first autowire
- warm autowire
- 1-dependency constructor
- 3-level graph
- closure with 0/1/3 dependencies
- method invocation
- property injection
- contextual resolution
- attribute resolution
- missing activation
- arbitrary `make()`
- arbitrary `call()`

The InterMix 10 development runtime should not regress materially from the optimized InterMix 9 baseline.

## 51. Production Performance Suite

Add dedicated compiler/runtime benchmarks:

- compiled container construction
- compiled public `get(string)`
- compiled internal dependency edge
- compiled singleton
- compiled transient
- compiled scoped
- compiled null singleton
- compiled 1-dependency graph
- compiled 3-level graph
- compiled 10-level graph
- compiled controller construction
- compiled method invocation
- compiled contextual dependency
- compiled tag lookup
- compiled static factory
- dynamic-island Closure
- unknown-class fallback
- dynamic deoptimization cost
- post-deoptimization lookup

Compare important operations with equivalent hand-written PHP.

The compiled runtime should approach hand-written PHP as closely as reasonably possible.

## 52. Benchmark Alternative Representations

Do not guess low-level PHP performance.

Explicitly benchmark:

- `match(string)` vs ID array
- integer array slot vs generated service method
- object property singleton cache vs array cache
- nullable resolved flags vs sentinels
- one generated dispatcher vs per-service methods
- direct method calls vs integer slot dispatch
- flattened code vs helper methods
- single generated class vs segmented generated classes

Keep the fastest maintainable implementation for PHP 8.4+.

## 53. External Performance Validation

After InterMix 10 benchmarks are stable:

1. run InterMix standalone benchmarks;
2. integrate InterMix 10 production runtime into Webrick;
3. rerun Webrick standalone HTTP benchmarks;
4. optimize Webrick;
5. integrate into Foundation;
6. optimize Foundation;
7. rerun InfByte minimal/full external benchmark;
8. compare against Flight and pure PHP again.

Do not evaluate InterMix only through microbenchmarks. The actual goal is lower complete request-path cost and higher sustained successful RPM.

## 54. Preserve Current Performance-Fix Work

The `perf/di-hot-path` branch is the optimized dynamic baseline.

Retain the lessons around cached-first resolution, null cache semantics, missing-hook lifetimes, same-line Closure identity, WeakMap Closure caches, compiled resolver activation, property fast exits, tracing fast exits, atomic definition state and request-shaped benchmarks.

InterMix 10 changes the architecture around these findings rather than replacing them with another generic container design.

## 55. Public API Direction

Because this is a major release, prefer explicit phase APIs.

Conceptual direction:

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
$builder->compile(
    path: '/cache/intermix.prod.php',
);
```

Production runtime:

```php
$container = ProductionContainer::load(
    '/cache/intermix.prod.php',
);
```

Exact naming may change, but keep the separation obvious.

Runtime application code should depend on a common runtime/PSR-11 contract rather than the builder.

## 56. Production Build Is Explicit

InterMix must never compile itself on a live request because an artifact is missing.

If a production artifact is absent or invalid, fail clearly; do not silently perform expensive compilation.

Framework integrations may provide deployment commands to generate the artifact.

## 57. Development Requires Zero Compiler Workflow

Development must not require developers to rebuild a container after every service edit.

Changes should immediately work through the dynamic graph.

No “compile after every change” development experience.

## 58. Production Debugging

Do not permanently instrument the production hot path merely to make debugging available.

Debugging can lazily load the metadata sidecar, instantiate a dynamic diagnostic resolver, reproduce/trace the requested service and leave the ordinary generated runtime untouched.

## 59. Memory Optimization

Performance review must include allocations and memory, not only wall-clock time.

Measure objects allocated per container boot, memory per generated container, Reflection instances, array counts, service-cache memory, generated artifact size and dynamic fallback allocation.

Avoid replacing CPU overhead with unbounded per-request memory growth.

## 60. Implementation Order

1. Freeze InterMix 9 semantic behavior with parity/regression tests.
2. Treat `perf/di-hot-path` as the optimized dynamic baseline.
3. Introduce an internal normalized definition graph.
4. Introduce `ContainerBuilder`.
5. Move mutation/configuration into builder.
6. Build `DevelopmentContainer` on the normalized graph.
7. Confirm the full current test suite against development mode.
8. Introduce compiler IR/plans only where required by generation.
9. Add environment folding.
10. Add alias flattening.
11. Add constructor/parameter planning.
12. Add lifetime planning.
13. Add contextual-binding planning.
14. Add attribute/property/method planning.
15. Add cycle validation.
16. Generate service slots/methods.
17. Implement minimal `ProductionContainer`.
18. Implement singleton/scoped runtime state.
19. Implement direct compiled internal dependency calls.
20. Implement tags/hooks.
21. Implement compiled call/make/invocation paths.
22. Implement arbitrary dynamic invocation fallback.
23. Implement runtime Closure/dynamic-definition islands.
24. Implement production deoptimization for true configuration mutation.
25. Separate diagnostic metadata.
26. Move artifact validation fully into deployment/build path.
27. Add safe optimization passes/inlining only after measurement.
28. Benchmark alternative generated representations.
29. Remove obsolete v9 resolver/repository machinery from production.
30. Run semantic parity matrix.
31. Run PHPBench matrix.
32. Run external Webrick benchmark.
33. Only then finalize the InterMix 10 public migration API.

## 61. Things We Should Explicitly Avoid

Do not gain speed by:

- dropping autowiring
- dropping attributes
- dropping contextual bindings
- dropping scopes
- dropping hooks
- dropping missing-service functionality
- banning Closures
- forcing CacheLayer
- forcing serialization
- weakening union/intersection support
- changing singleton/scoped identity
- silently ignoring unsupported compiled definitions
- having dev require compilation
- running the compiler automatically in production requests
- maintaining two subtly different DI semantics
- exposing Webrick/Foundation-specific behavior inside InterMix
- adding a large runtime abstraction layer merely for architectural neatness

The development and production engines may be completely different internally. Their **observable DI behavior must remain equivalent**.

## 62. Final Architecture Target

```text
                        ┌─────────────────────┐
                        │  ContainerBuilder   │
                        │                     │
                        │ definitions         │
                        │ contexts            │
                        │ attributes          │
                        │ environments        │
                        │ hooks               │
                        │ validation          │
                        └─────────┬───────────┘
                                  │
                     ┌────────────┴─────────────┐
                     │                          │
                     ▼                          ▼
          DEVELOPMENT                    PRODUCTION BUILD
     ┌────────────────────┐            ┌────────────────────┐
     │DevelopmentContainer│            │     Compiler       │
     │                    │            │                    │
     │ Reflection         │            │ graph analysis     │
     │ Mutable            │            │ optimization       │
     │ Dynamic            │            │ code generation    │
     │ Traceable          │            └─────────┬──────────┘
     └────────────────────┘                      │
                                                ▼
                                    ┌────────────────────────┐
                                    │ Generated PHP Artifact │
                                    └───────────┬────────────┘
                                                │
                                                ▼
                                    ┌────────────────────────┐
                                    │ ProductionContainer    │
                                    │                        │
                                    │ direct service methods │
                                    │ singleton state        │
                                    │ scope state            │
                                    │ zero reflection        │
                                    │ zero config mutation   │
                                    │ lazy dynamic fallback  │
                                    └────────────────────────┘
```

The production runtime should feel less like a general-purpose DI framework and more like **hand-written application wiring generated by InterMix**.

That is the performance bar for InterMix 10.

The development runtime remains the full flexible DI engine developers expect.

**Dev: maximum flexibility.**

**Prod: maximum specialization.**

**Features: preserved.**

**Known request path: as close to raw PHP as we can make it.**
