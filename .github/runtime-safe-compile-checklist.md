# Runtime-safe compile optimization checklist

Branch-only implementation tracker for PR #131. Remove this file before the PR is marked ready.

Safety invariant: if static equivalence is provable, generate the faster path; otherwise preserve the existing runtime path unchanged.

## Completed
- [x] Create `perf/runtime-safe-compile` from current `main`.
- [x] Add internal `ScopeState::$hasSeeds` precomputation (`718aa502`).
- [x] Add direct unit coverage for `ScopeState::$hasSeeds` (`da5de8ff`).
- [x] Open draft PR #131 for CI/review visibility.
- [x] Use `hasSeeds` in standard generated seed guards (`2bb061b`).
- [x] Add generated-artifact parity coverage for the seed fast path (`018e4aa`).
- [x] Use `hasSeeds` in compiled invocation seed guards (`0d0d29b`).
- [x] Use `hasSeeds` in lifecycle-hook seed guards (`0a666eb`).
- [x] Statically plan public static methods (`5668162`).
- [x] Render public static methods as direct `Class::method(...)` calls (`50e13c6`).
- [x] Add direct static-method compilation coverage (`d593e00`).
- [x] Preserve statically-known `#[Inject]` service arguments for private/readonly properties (`c5ae56d`).
- [x] Add private/readonly property injection fast-path coverage (`27df470`).
- [x] Fix the only initial CI failure: Pint property ordering (`eb799402`).
- [x] Add backward-compatible generated tag/runtime-parameter hooks and reuse cached reflection metadata (`97fd611`).
- [x] Retain compiled method parameter names for safe runtime overrides (`e5978fd`).
- [x] Generate supplied-parameter fresh invocation for non-variadic statically planned methods (`773aa41`).
- [x] Add named/positional runtime-parameter parity and variadic fallback coverage (`5612ebc`).
- [x] Generate direct eager/lazy compiled tag dispatch with deoptimization checks (`6fac721`).
- [x] Add eager/lazy tag dispatch parity and identity coverage (`c35d582`).

## In progress
- [ ] Isolate mutable scope state per execution context for coroutine/Fiber safety without a Swoole dependency.

## Pending
- [ ] Add interleaving concurrency regression coverage for dynamic and generated containers.
- [ ] Expand production request-path benchmarks for scoped, invocation, tag, runtime-island, and context-isolation paths.
- [ ] Run/inspect full CI and benchmark results; fix regressions.
- [ ] Remove this branch-only checklist and mark PR ready only when all items pass.
