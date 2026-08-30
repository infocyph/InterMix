# Runtime-safe compile optimization checklist

Branch-only implementation tracker for PR #131. Remove this file before the PR is marked ready.

Safety invariant: if static equivalence is provable, generate the faster path; otherwise preserve the existing runtime path unchanged.

## Completed
- [x] Create `perf/runtime-safe-compile` from current `main`.
- [x] Add internal `ScopeState::$hasSeeds` precomputation (`718aa502`).
- [x] Add direct unit coverage for `ScopeState::$hasSeeds` (`da5de8ff`).
- [x] Open draft PR #131 for CI/review visibility.
- [x] Use `hasSeeds` in generated seed guards (`2bb061b`).
- [x] Add generated-artifact parity coverage for the seed fast path (`018e4aa`).

## In progress
- [ ] Compile public static methods directly when their arguments are statically representable.

## Pending
- [ ] Preserve compile-known `#[Inject]` values for non-public properties through the existing assignment path.
- [ ] Generate direct compiled tag resolution while preserving fallback merge/lazy semantics.
- [ ] Compile `resolveNow()` / fresh method invocation with supplied runtime parameters only when binding semantics are provably equivalent.
- [ ] Cache immutable runtime-island reflection metadata without caching request-specific values.
- [ ] Isolate mutable scope state per execution context for coroutine/Fiber safety without a Swoole dependency.
- [ ] Add interleaving concurrency regression coverage for dynamic and generated containers.
- [ ] Expand production request-path benchmarks for scoped, invocation, tag, runtime-island, and context-isolation paths.
- [ ] Run/inspect full CI and benchmark results; fix regressions.
- [ ] Remove this branch-only checklist and mark PR ready only when all items pass.
