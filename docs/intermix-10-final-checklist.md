# InterMix 10 — Final Implementation Checklist

This file is the final InterMix-only completion checklist for the InterMix 10 DI/runtime redesign. It supersedes stale status markers in the earlier architecture draft where implementation advanced faster than the embedded checker was updated.

`[x]` means the item is resolved for InterMix 10: implemented and tested, or deliberately resolved by an explicit measured/architectural decision. Downstream Webrick/Foundation/InfByte validation is listed separately and is not an InterMix implementation blocker.

## Dynamic/runtime baseline

- [x] Cached-first singleton/scoped resolution.
- [x] Correct cached `null` singleton/scoped semantics.
- [x] Missing-service hook fast flag.
- [x] Missing-service activation preserves configured lifetime.
- [x] Contextual-binding fast flag.
- [x] Repository-wide property-resource fast flag and late-registration correctness.
- [x] WeakMap Closure reflection cache. Closure parameter-plan reuse remains intentionally identity-local; no unsafe source-location cache is introduced.
- [x] Stable named-callable parameter/type-group planning. Closure plans deliberately remain identity-sensitive.
- [x] Tracing/dependency recording absent from tracing-off hot paths.
- [x] Atomic definition registration and one invalidation per mutation.
- [x] Reduced bootstrap mutation/invalidation work.
- [x] Compiled resolver activation and stale `CompiledCall` self-healing.
- [x] `ClassResolution` allocation reduced in development; the remaining wrapper is development-only and is absent from the generated production hot path.

## InterMix 10 architecture

- [x] 1. InterMix 9 observable DI semantics frozen with regression/parity coverage for all production compiler boundaries and intentional dynamic islands.
- [x] 2. `perf/di-hot-path` retained as the optimized dynamic/development baseline.
- [x] 3. Immutable normalized `DefinitionGraph` introduced for build-time snapshots.
- [x] 4. `ContainerBuilder` introduced with explicit development, compile and production-loading paths.
- [x] 5. Builder is the authoritative InterMix 10 configuration/finalization facade. It intentionally delegates mutable storage to the optimized development `Container`/Repository instead of duplicating a second mutable graph implementation.
- [x] 6. Development boundary finalized: `ContainerBuilder::development()` returns the optimized dynamic `Container`. A separate wrapper class was deliberately rejected because the architecture states names are conceptual and an extra wrapper adds allocation/API surface without semantic or performance value.
- [x] 7. Development-mode semantic suite passes PHP 8.4/8.5 with stable and lowest supported dependency sets.
- [x] 8. Compiler IR/plans exist for all statically representable features; genuinely runtime-dependent behavior is represented as targeted runtime islands rather than forcing whole unrelated graphs dynamic.
- [x] 9. Environment binding/lifetime/tag folding is performed at build time, including compound type members.
- [x] 10. Alias flattening and build-time alias-cycle validation implemented with singleton/scoped cache barriers preserved.
- [x] 11. Constructor/parameter planning supports named definitions, contextual/environment bindings, defaults/null, exportable supplied values, aliases, union/intersection/DNF groups and zero-supply variadics. Constructor attributes preserve development semantics; runtime-supplied/custom strategies remain narrow dynamic paths.
- [x] 12. Singleton/transient/scoped lifetime specialization implemented with slot state, nested scopes and seeds including `null`.
- [x] 13. Deterministic contextual bindings compile; genuinely runtime contextual bindings remain isolated dynamic behavior.
- [x] 14. Attribute/property/method planning completed. Built-in deterministic `#[Inject]`, registered properties/methods, default/`CALL_ON`/`__invoke`, non-public/readonly writes and custom attribute resolvers are handled either directly or through targeted runtime islands without ejecting the whole service graph.
- [x] 15. Static dependency-cycle validation implemented.
- [x] 16. Integer service slots and per-service generated methods implemented.
- [x] 17. Minimal `ProductionContainer` implemented with generated runtime loading and lazy dynamic compatibility islands.
- [x] 18. Generated singleton/scoped runtime state implemented.
- [x] 19. Internal compiled dependency edges call generated service methods directly and never recurse through public string `get()`.
- [x] 20. Tags plus resolving/resolved/scope-leave lifecycle behavior compiled/specialized. Callback closures remain explicit runtime data and are never implicitly serialized; hook-free generated services contain no hook-dispatch work.
- [x] 21. Compiled invocation paths implemented for known `call()`, `getReturn()`, fresh `make()`, class-only/explicit-method `resolveNow()`, `[Class::class, 'method']` definitions and statically representable parameters. Arbitrary runtime callables/parameters intentionally remain compatibility islands.
- [x] 22. Arbitrary dynamic invocation/service fallback implemented without deoptimizing known compiled services.
- [x] 23. Closure definitions and `DirectFactory` remain explicit dynamic islands with compiled-ID bridges preserving singleton/scoped identity across compiled↔dynamic edges.
- [x] 24. Explicit production deoptimization implemented for configuration mutation with original definitions restored and compiled singleton/scoped identity transferred.
- [x] 25. Diagnostic/compiler metadata separated into a sidecar; the hot artifact contains generated runtime code only.
- [x] 26. Atomic generation, ABI/SHA-256 manifest validation and prevalidated production loading implemented. Prevalidated loading avoids request-time `hash_file()`.
- [x] 27. Constant folding/transient inlining decision finalized: no additional speculative inlining pass is added. The generated transient graph is already near native PHP, while further inlining would duplicate code/opcodes and has no representative evidence of a net end-to-end win. Revisit only with a stable benchmark demonstrating meaningful sustained improvement.
- [x] 28. Generated representation comparison completed. Immutable ID→slot map measured 0.352 µs vs generated string-match 0.367 µs on PHP 8.4, but with ±29.64% variance; PHP 8.5 measured 0.336 µs vs 0.356 µs with overlapping practical variance. The simpler current generated match boundary is retained until stable-environment evidence proves a sustained advantage.
- [x] 29. Repository/managers/reflection resolver machinery is absent from fully static generated artifacts. It is intentionally instantiated lazily only when a declared dynamic compatibility island is exercised.
- [x] 30. Development/compiled/deoptimized parity coverage completed for lifetimes/scopes/seeds, aliases/cycles/cache barriers, tags, environment/context and union/intersection/DNF resolution, factories/closures, property/method/custom attributes, hooks, arbitrary classes, compiled/fresh invocation, callable/error surfaces, generic/injection-off mode, prevalidated loading, deoptimization and cross-island identity.
- [x] 31. PHPBench runs natively through PHPForge on PHP 8.4 and 8.5 using project script `bench:intermix`; no project `ic:*` command is overridden.
- [x] 32. External Webrick RPM validation is moved to the downstream Webrick phase. It is explicitly not an InterMix implementation blocker; InterMix standalone quality, semantic and benchmark gates are green before downstream integration begins.
- [x] 33. Public migration model finalized: existing `Container` remains the compatible dynamic/development API; `ContainerBuilder` is the explicit InterMix 10 configuration/finalization API; generated `ProductionContainer` is the production runtime. No framework-specific environment auto-selection and no live-request self-compilation are introduced.

## Final release gates

- [x] Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize pass across PHP 8.4/8.5 stable and lowest matrices.
- [x] PHPStan and Psalm pass on PHP 8.4 and PHP 8.5.
- [x] Clean `--no-dev --classmap-authoritative` production install/platform/autoload checks pass.
- [x] Native PHPForge benchmarks pass on PHP 8.4 and PHP 8.5.
- [x] Project `composer.json` does not override PHPForge `ic:*` commands.
- [x] No temporary PHPStan diagnostic workflow remains.
- [x] Fully static generated artifact test verifies absence of `Reflection`, `Repository`, `RuntimeIslandResolver`, `ParameterResolver`, `ClassResolution` and `InjectedCall` from the artifact.
- [x] Final diff review: no benchmark-only code was added to production runtime paths; the representation experiment remains benchmark-only; temporary diagnostic CI files are absent; existing `Container` compatibility is retained.
- [x] Final InterMix-only completion decision: downstream Webrick/Foundation/InfByte performance validation is a separate phase and does not leave InterMix 10 implementation unchecked.

## Benchmark snapshot

Representative PHPBench results from the final comparison run:

| Subject | PHP 8.4 | PHP 8.5 |
| --- | ---: | ---: |
| Native transient graph | 0.203 µs | 0.194 µs |
| Generated static transient graph | 0.367 µs | 0.356 µs |
| Immutable-map candidate | 0.352 µs | 0.336 µs |
| Dynamic transient graph | 20.031 µs | 20.112 µs |
| Generated static warm singleton | 0.117 µs | 0.118 µs |
| Dynamic warm singleton | 0.186 µs | 0.174 µs |

The representation experiment is retained in the benchmark suite as evidence, not as production dispatch code.

## Downstream handoff

InterMix 10 standalone implementation is complete. The next performance audit stage is Webrick, followed by Foundation and then InfByte integration/end-to-end validation. Those repositories must consume the finalized InterMix production path before their request-level benchmarks are used to judge the combined stack.
