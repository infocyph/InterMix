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
- [x] Fix initial Pint property ordering (`eb799402`).
- [x] Add backward-compatible generated tag/runtime-parameter hooks and reuse cached reflection metadata (`97fd611`).
- [x] Retain compiled method parameter names for safe runtime overrides (`e5978fd`).
- [x] Generate supplied-parameter fresh invocation for non-variadic statically planned methods (`773aa41`).
- [x] Add named/positional runtime-parameter parity and variadic fallback coverage (`5612ebc`).
- [x] Generate direct eager/lazy compiled tag dispatch with deoptimization checks (`6fac721`).
- [x] Add eager/lazy tag dispatch parity and identity coverage (`c35d582`).
- [x] Identify Fiber/Swoole/OpenSwoole execution contexts without adding a runtime dependency (`2a13832`, `00359c9`).
- [x] Add per-context dynamic scope state and enable it in `Container` (`ca2484e`, `d49adc3`, `68b0065`).
- [x] Isolate compiled `ProductionContainer` scope chains by execution context (`f6e0d45`).
- [x] Centralize compile-time scope access and apply it to invocation/lifecycle/main generated service paths (`f8528ac`, `534f93c`, `0cedbec`, `84baba6`).
- [x] Add interleaving Fiber regression coverage for dynamic and generated containers (`9e70ed9`).
- [x] Keep ordinary dynamic resolution on the existing non-context path until a concurrent scope is active (`a27233b`).
- [x] Add production request-path benchmark coverage for scoped graphs, seeds, method/controller invocation, direct/lazy tags, runtime islands, Fiber contexts, and native controls (`ab93836`).
- [x] Complete production benchmark coverage with artifact/prevalidated loads, direct static methods, private `#[Inject]`, hybrid fallback, and sequential scope enter/resolve/leave controls (`89e5b37`).
- [x] Reduce `ProductionContainer` analyzer complexity by moving only cold fallback/spec handling into internal helpers (`cca0220`, `30c98d6`, `d7478b6`).
- [x] Restore non-public post-construction methods to the existing runtime-island path after parity testing exposed the regression (`5b8e5f2`).
- [x] Fix validation-only formatting findings in runtime requirements and benchmark ordering (`b70079c`, `f16f169`).
- [x] Restore the repository's native PHPForge workflow with no project-side benchmark command override or debug job (`c174f44`).
- [x] Validate PHP 8.4 prefer-lowest and prefer-stable QA successfully.
- [x] Validate PHP 8.5 prefer-lowest and prefer-stable QA successfully.
- [x] Validate PHPStan/Psalm analysis successfully on PHP 8.4 and PHP 8.5.
- [x] Validate production `--no-dev` clean install and platform requirements successfully.
- [x] Confirm dynamic and compiled same-name scope/seed isolation across interleaved Fibers in the full QA matrix.
- [x] Keep benchmark execution/discovery owned by PHPForge; no benchmark script or CI override is added to InterMix.
- [x] Confirm the branch is based directly on current `main` with no divergence (`behind_by=0`).

## Final cleanup
- [x] All planned implementation work is complete.
- [x] All repository QA/analyzer/install gates are green on the final implementation head.
- [x] No public-facing API or intended behavior change is introduced; non-provable cases retain the existing dynamic/runtime-island path.
- [ ] Delete this temporary checklist and finish PR #131 metadata.
