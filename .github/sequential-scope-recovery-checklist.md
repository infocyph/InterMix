# Sequential scope recovery checklist

Branch-only tracker for recovering the DI 130-era sequential/dynamic scope performance while preserving the merged 131 compiler/runtime gains and execution-context isolation.

## Safety invariant
- Do not change public APIs or observable DI semantics.
- Do not touch generated production compiler/runtime behavior unless validation proves necessary.
- Preserve Fiber/Swoole/OpenSwoole scope isolation.
- Optimize only the dynamic/sequential repository paths implicated by the 131 benchmark regression.

## Work
- [x] Create `perf/recover-sequential-scope` from current `main`.
- [x] Remove sequential `parent::...` scope dispatch from `ConcurrentRepository` (`e15fa306`).
- [x] Preserve per-context Fiber/Swoole/OpenSwoole scope state (`e15fa306`).
- [x] Cache runtime coroutine capability detection without changing context ID prefixes (`2b8dc01`, `0dc5535`).
- [x] Add mixed sequential/concurrent scope and leave-hook regression coverage (`d8e2683`).
- [x] Split concurrent-only state into lazy `ExecutionScopeStore` while keeping sequential state direct (`f648ad8`, `c999f53`).
- [x] Keep sequential scope leave inline while isolating concurrent leave complexity (`523dfce`).
- [x] Patch validation findings only: Pint ordering and PHPStan's redundant coroutine callable guard (`93521aa`, `92ab29c`).
- [x] Remove the temporary PHPStan diagnostic workflow (`9931d70`).
- [x] Run full PHPForge QA/analyzer matrix: PHP 8.4/8.5 × stable/lowest, PHPStan, Psalm, and clean install all green on `9931d702`.
- [ ] Inspect updated benchmark against DI 130 and DI 131.
- [ ] Patch only benchmark regressions if the new comparison identifies any.
- [ ] Remove this checklist before completion.
