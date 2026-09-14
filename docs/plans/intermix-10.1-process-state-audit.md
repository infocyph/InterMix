# InterMix 10.1 — Persistent Process-State Audit

This audit supports the InterMix 10.1 structured-runtime hardening work. The rule is simple: process-wide mutable state may hold configuration/runtime metadata, but it must not silently become a request/job/session store in persistent workers.

| State | Lifetime / bound | Cleanup / hardening | Persistent-worker rule |
| --- | --- | --- | --- |
| `Container::$instances` | Process/application alias registry; cardinality follows explicitly created aliases | `Container::unset()` removes the current alias | Use stable application aliases only. Never derive aliases from request, tenant, principal or job IDs. |
| `Container::parseCallable()` descriptor cache | Process cache, hard-bounded to `CALLABLE_DESCRIPTOR_CACHE_LIMIT = 512` | Oldest entry evicted at the bound | Stores callable descriptors only; no resolved service or request state. |
| `Invoker::$sharedInstance` | One process singleton | Bound to the stable `Container::DI_ALIAS` container | Configuration/runtime helper only; application code needing isolation should use `Invoker::with()`. |
| `ReflectionResource::$reflectionCache` | Process reflection metadata, bounded per bucket by the configured cache limit (default 2048) | `clearCache()` and `setCacheLimit()`; oldest entries evicted | Reflection metadata only. Must never cache resolved services or contextual values. |
| `ReflectionResource::$closureReflectionCache` | Weak closure-reflection cache | `WeakMap`; entries disappear with Closure keys; `clearCache()` available | Safe for persistent execution because keys are weak. |
| `Fence::$classExistsCache` / `$extensionExistsCache` | Process capability metadata, each bounded to 256 | Oldest entry evicted at the bound | Capability metadata only. |
| `Fence::$instances` / `$limitOverride` | Explicit Fence consumer registry/configuration | Consumer limit, `clearInstances()` and `reset()` | Treat keys as configuration/application identifiers, never request-derived identifiers. |
| `MacroMix::$macros` and Closure metadata maps | Class-level application configuration; intentionally follows registered macro names | `removeMacro()` removes a registration; optional process lock protects mutation | Register macros during bootstrap/configuration. Never use request/job/session-derived macro names or capture request principals in long-lived macro closures. |
| `ExecutionContext` carrier resolver/token state | Process runtime metadata | Fiber/coroutine object tokens are weakly keyed; numeric Swoole/OpenSwoole CID fallback is paired with deterministic scope-store cleanup | Stores opaque carrier identity only; never logical scope contents. |
| Dynamic `ExecutionScopeStore` / compiled `ProductionScopeStore` | Container-owned, not static | Carrier-local reset/detach and owner-close liveness rules | Request/job scoped objects live here, not in static registries. |

## Findings

1. No InterMix static registry is required to hold request principals, sessions, request/job DI scope objects or task-local values.
2. The callable and reflection/capability caches are explicitly bounded or weakly keyed.
3. Container aliases, Fence instances and MacroMix macros are configuration-lifetime registries. Their APIs remain intentionally process-wide; persistent runtimes must use stable bootstrap identifiers rather than request-derived keys.
4. `ExecutionContext` no longer uses reusable Fiber `spl_object_id()` values as durable carrier keys. Object-backed Fiber/coroutine carrier tokens are weakly keyed and process-local.
5. Numeric coroutine IDs remain a compatibility fallback only when a loaded Swoole/OpenSwoole runtime does not expose an object context. Scope state still has deterministic leave/reset/detach cleanup, and extension-specific lifecycle coverage remains part of the optional carrier matrix.
6. Dynamic and compiled scope stores are container-owned and therefore isolated from unrelated container instances; they are the only place where logical scoped service identity is retained across carriers.

## Release rule

Any future process-wide mutable state must be classified in this audit (or its successor) as one of: bounded cache, weak cache, explicit configuration registry, or resettable runtime metadata. Request/job/session values must remain container/task-local and must not be added to process-global registries.
