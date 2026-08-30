# Sequential scope recovery checklist

Branch-only tracker for recovering the DI 130-era sequential/dynamic scope performance while preserving the merged 131 compiler/runtime gains and execution-context isolation.

## Safety invariant
- Do not change public APIs or observable DI semantics.
- Do not touch generated production compiler/runtime behavior unless validation proves necessary.
- Preserve Fiber/Swoole/OpenSwoole scope isolation.
- Optimize only the dynamic/sequential repository paths implicated by the 131 benchmark regression.

## Work
- [x] Create `perf/recover-sequential-scope` from current `main`.
- [ ] Remove sequential `parent::...` scope dispatch from `ConcurrentRepository`.
- [ ] Preserve per-context Fiber/Swoole/OpenSwoole scope state.
- [ ] Cache runtime coroutine capability detection without changing context IDs.
- [ ] Add mixed sequential/concurrent scope regression coverage.
- [ ] Run full PHPForge QA/analyzer matrix.
- [ ] Inspect updated benchmark against DI 130 and DI 131.
- [ ] Patch only concrete regressions found by validation.
- [ ] Remove this checklist before completion.
