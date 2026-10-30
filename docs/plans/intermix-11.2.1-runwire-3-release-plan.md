# InterMix 11.2.1 audit and Runwire 3 release plan

Date: 2026-10-30  
Base revision: `2873c51` (`main`, tagged `11.2`)  
Review branch: `patch/runwire-3` at `fc99c1bc2c1fab2154c8d73ce4c35b0e7329c2e7` (one commit ahead of `main` at the time of this review).  
Delivery: draft PR [#146](https://github.com/infocyph/InterMix/pull/146) on `patch/runwire-3`, targeting **11.2.1**. Regression implementations are committed; final exact-head validation and F2 review remain.

## PR completion and ownership

All implementation, review, validation and evidence collection in this plan must
finish within the PR. Finalize the PR with a reviewable diff, the exact validated
head SHA, check results and an updated findings checklist, then hand it over.

Do not merge the PR, enable auto-merge, create or push a release tag, or publish a
release. The user will manually merge and tag later. Merging, tagging and
publication are outside this plan's execution and completion criteria.

## Integration targets

- Runwire **3** only: use `infocyph/runwire:^3.0` and validate the published 3.0
  baseline. Do not retain or add Runwire 2.x compatibility lanes or shims.
- CacheLayer **4.0.2** baseline only: use `infocyph/cachelayer:^4.0.2` and validate
  4.0.2 explicitly. Do not retain or add acceptance lanes for earlier CacheLayer
  versions. Apply the same minimum to current documentation examples and isolated
  consumer installation commands, including the existing workflow's `^4.0` command.

These are optional integration targets, not new production requirements. Stable
and lowest dependency checks must respect these declared minimums. Historical
release documents remain archival records, not additional supported test targets.

## Scope and engineering constraints

This review includes the existing changes to Composer, README, documentation,
Runwire bridge PHPDoc, documentation configuration, release validation, and this
plan. It follows `vendor/infocyph/phpforge/resources/engineering-principles.md`
and its referenced `AGENTS.md` workflow.

- Correctness, security, data integrity and operational stability are prerequisites.
- Keep behavior in its existing cohesive owner. Prefer a small private helper over
  a new abstraction when centralization is needed.
- Preserve public parameter names, PSR contracts, compiler ABI 3, manifest/fallback
  validation, and optional provider boundaries.
- Workers, loops, runtime/request/coroutine objects, cancellation and provider
  lifecycles remain host-owned. Propagate supplied identities without recreating
  or closing host objects.
- Keep existing detector scope, rules and thresholds. Do not suppress findings,
  expand baselines, weaken assertions, or relax performance/resource budgets.
- Measure meaningful hot-path changes using matched baseline/candidate workloads;
  component benchmarks alone do not establish sustained host RPM.

The version update and planned bug fix do not introduce a public capability or
require an InterMix API/ABI break. A patch is appropriate. Reassess the version
only if implementation introduces a public contract change.

## Findings

| ID | Priority | Verified finding | Status |
| --- | --- | --- | --- |
| F1 | P1, release blocker | Explicit definition-cache keys omitted selected environment. Two containers sharing a pool, namespace, generation and service ID could reuse another environment's value. | **Implemented:** typed environment/service identity with regression cases for both construction orders, unset/literal names. Awaiting exact-head QA. |
| F2 | P2, engineering follow-up | Baseline PHPProbe reports 29 clone groups, 69 occurrences and 1,143 duplicated lines (4.49%). Clone groups require semantic triage, not automatic consolidation. | **Partial:** duplicate trusted-artifact shape validation is now shared by both compiled resolver load paths. Rerun detector and triage remaining groups; no thresholds or suppressions changed. |
| F3 | P2, package hygiene | Composer ZIP included an empty local `.aws/` directory. No credentials or other `.aws` files were present. | Fixed with `.aws export-ignore`; release archive assertions also check local/generated directories. Rebuilt ZIP is clean. |
| F4 | Required compatibility maintenance | Existing edits move the tested Runwire dependency and isolated consumer from `^2.1.1` to `^3.0`. | Reviewed and retained; installed 3.0 passes focused integration tests. |
| F5 | Informational dependency maintenance | `doctrine/annotations` is abandoned, through development-only `phpbench/phpbench`. | Absent from the production installation. Follow upstream tooling; do not add consumer runtime conflicts or a local pin. |
| F6 | Evidence correction | The previous draft named CacheLayer 4.0.1, omitted F1/F2, and claimed local verification was complete. | Replaced with current 4.0.2 evidence and explicit unresolved work. Historical dependency/advisory claims without a preserved baseline are not treated as verified findings. |
| F7 | P1, release blocker | The Runwire bridge admitted an already-cancelled or deadline-expired `RequestContext` when it was not completed. | **Implemented:** entry and explicit `checkpoint(request:)` validate the request cancellation token; added canceled/expired and live-coroutine regressions. Awaiting exact-head QA. |
| F8 | P2, lifecycle ownership | A bridge opting into CacheLayer lifecycle ownership could release a compatible runtime binding it had not acquired. | **Implemented:** release only when the bridge actually acquired the downstream binding; added pre-bound and active-boundary tests. Awaiting exact-head QA. |
| F9 | P2, identity-contract review | Legacy cache-key path conflated an unset environment/generation with literal `default` values; the explicit namespace key ignores local revision rotation. | **Implemented/decided:** legacy keys use unambiguous typed identities; explicit cache generation remains the cross-builder invalidation contract, not local revision. Documented and regression-tested; awaiting exact-head QA. |

Composer's live audit reports **zero known dependency advisories**. This does not
prove that the library has no vulnerabilities. F1 is a confirmed isolation and
correctness defect with potential confidentiality consequences when environments
share a backend; no remote exploit or CVE classification is claimed.

## F1: reproduction and root-cause fix

Owner: `src/DI/Resolver/Concerns/ManagesDefinitionCache.php`,
`makeDefinitionCacheKey()`, explicit namespace branch around line 84.

The legacy branch incorporates environment in its service-key digest but substitutes
`default` when the environment is null. The explicit builder branch incorporates
namespace, generation and service ID but omits `getEnvironment()`, despite the
method's documented isolation contract. The legacy generation prefix also
substitutes `default` when generation is null. These sentinel identities need
unambiguous treatment.

Reproduction, run from the repository root:

```php
<?php
require 'vendor/autoload.php';

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\ContainerBuilder;

$pool = Cache::memory('audit.' . bin2hex(random_bytes(4)));
foreach (['staging', 'production'] as $environment) {
    $container = ContainerBuilder::create('audit.' . $environment)
        ->setEnvironment($environment)
        ->definitionCache($pool, 'application', 'same-release')
        ->factory('settings', static fn() => ['environment' => $environment])
        ->cacheDefinition('settings')
        ->build();
    echo $environment . ': ' . $container->get('settings')['environment'] . "\n";
}
```

Observed: `staging: staging`, then **`production: staging`**. Expected second
result: `production: production`. Both environments also produced identical keys
when inspected through the package's internal repository accessor.

Implementation and acceptance:

1. Add a regression in the existing builder/cache integration tests that fails on
   the current implementation. Exercise one shared pool, identical explicit
   namespace/generation/ID, and distinct selected environments.
2. Include environment in the explicit key's deterministic hash input in the
   existing trait. Keep opaque PSR-6-safe keys and bounded key lengths. Use an
   unambiguous encoding so an unset environment cannot alias a literal environment
   string through a sentinel choice. Do not create a new cache subsystem.
3. Test both construction orders, null and named environments (including a
   literal `default`), actual returned values, factory invocation counts, and key
   inequality. Also test null versus literal `default` generation in the legacy
   configuration path. For explicit keys, verify same-environment reuse is not
   accidentally disrupted by builder-local configuration mutations. Preserve reuse between
   equivalent same-environment builders with different local aliases or declaration
   order, plus existing namespace/generation isolation and cached-null behavior.
4. Do not fall back to an old environment-agnostic key: that would reintroduce the
   defect. Document that the corrected identity causes a cache miss/repopulation;
   hosts can rotate their generation and retire old entries using provider policy.
5. Until fixed, applications sharing a backend should include their environment
   in the explicit namespace or generation.
6. Run focused cache tests, PHPForge processors and the full release guard. Compare
   definition-cache component behavior and representative host results without
   changing budgets. No public API or compiled ABI change is needed.

## F2: duplication review and bounded consolidation

Reproduce with `composer ic:test:duplicates`. The installed configuration uses
`fail_on=error` and `error_duplicate_percentage=10`; the observed 4.49% is below
that failure threshold. No threshold/configuration change is proposed.

Prioritize actual repeated policy, including artifact parsing/shape checks in
`CompiledResolverGenerator` (lines 114 and 383), repeated fresh-invocation dispatch
rendering in `StaticFreshInvocationRenderer` (lines 89 and 115), and contextual
binding-shape projection in `DefinitionGraph` (line 194) and `Repository`
(line 465). Review all affected callers before selecting their owner.

Triage the complete detector inventory by responsibility:

| Clone groups | Review surface |
| --- | --- |
| 1, 9, 10 | Builder/repository configuration and lifecycle operations |
| 2, 5, 14, 19, 20, 23 | Generated invocation/runtime/lifecycle source and benchmark setup |
| 3, 4, 6, 7, 15, 16, 21, 25, 28, 29 | Sequential, concurrent and production scope state and cleanup |
| 8, 17, 24, 26, 27 | Injection, reflection, ancestry cleanup and binding metadata |
| 11, 13, 18, 22 | Artifact loading/publication and similarly shaped state updates |
| 12 | Signed and unsigned closure parsing |

For each valid same-policy duplicate, centralize it in the existing cohesive owner
and update every affected call site. Preserve source-free behavior, cancellation,
exception chaining, cleanup ordering and carrier isolation. Keep signed HMAC
verification before deserialization; do not combine unrelated state mutations or
security policies merely because normalized syntax looks alike. Document any
false-positive classification with source semantics, without suppression or a
weaker detector configuration.

Run scope/compiler/serializer parity tests as applicable, then the full suite and
representative benchmarks for any changed runtime hot paths. Prefer private
helpers and existing owners; do not force a broad inheritance or wrapper layer.

## F7: Runwire request cancellation and deadline admission

Owner: `src/Integration/Runwire/RunwireIntegration.php`,
`validateBoundary()`, `checkpoint()`, and
`tests/Integration/RunwireBridgeIntegrationTest.php`.

Runwire 3's `RequestContext` exposes a cancellation token whose
`throwIfCancelled()` refreshes deadline expiry and throws the native
`CancelledException`. The current InterMix boundary checks `completed()`,
`runtime()` and, when supplied, the coroutine scope's cancellation. The request
token is not checked; a caller may therefore enter user code after cancellation
or deadline expiry, especially when no coroutine scope is passed. CacheLayer's
own Runwire checkpoint checks request cancellation separately, so delegation
cannot be assumed to happen at every InterMix request boundary.

Acceptance:

1. Add failing parameterized tests for a cancelled (but not completed) request
   passed to `withinRequest()` with no coroutine scope and with an otherwise
   live coroutine scope, using the existing dynamic/compiled/prevalidated
   integration variants. Assert the callback does not run and no DI scope,
   task-local value or CacheLayer context leaks.
2. Add an expired-deadline case with controlled time/deadline construction where
   the Runwire 3 API supports it; assert native reason/exception propagation.
   Preserve the existing completed/mismatched-runtime checks and their errors.
3. Propagate request cancellation at entry and at the public `checkpoint()`
   boundary when an active request is available; do not create new cancellation
   sources, complete requests, close scopes, or alter the host's failure mode.
   Check provider contract tests before using any ambient context.
4. Test nested child work, cancellation races, and exception-safe restoration of
   borrowed contexts. Run focused bridge tests under published Runwire 3 and
   full provider acceptance under PHP 8.4/8.5.

## F8: downstream CacheLayer lifecycle acquisition

Owner: `src/Integration/Runwire/RunwireIntegration.php`,
`bindCacheLayer()` and `release()`.

`ownsCacheLayerLifecycle: true` currently releases the downstream runtime
after a successful InterMix bind even when CacheLayer was already bound to that
exact runtime before the bridge was constructed. CacheLayer's static
`release()` clears its worker binding and memoizer state. This can tear down a
different integration's existing binding. The default borrower case already has
a regression test; the explicitly owned, pre-bound case does not.

Acceptance:

1. Reproduce with a host pre-binding CacheLayer to runtime A, then an InterMix
   bridge with `ownsCacheLayerLifecycle: true` binding and releasing A. Verify
   whether the existing CacheLayer worker binding and memoizer state are retained
   or erroneously cleared.
2. Define the contract precisely: owning *the act of binding* must be
   distinguishable from merely observing a compatible preexisting binding.
   Prefer recording an actual acquisition in the current bridge; do not
   globally refcount or introduce a new lifecycle manager.
3. Test two borrower bridges and a single owner, duplicate bind/release,
   conflict with runtime B, and release while request boundaries are active.
   Preserve the host's explicit choice of lifecycle owner.

## F9: explicit cache generation versus local revision

The public repository trait exposes `rotateDefinitionCacheGeneration()`,
which increments its local revision and resets the memoized prefix. The
namespace-specific `makeDefinitionCacheKey()` branch hashes only namespace,
explicit generation, and service ID, so an explicit key is unchanged by a
local revision. The legacy branch includes revision in its prefix. This is a
contract inconsistency, not sufficient evidence by itself to change the
explicit cache's stable cross-builder identity.

Acceptance:

1. Establish whether revision rotation is an advertised operation on the
   explicit builder cache. Review the builder, definition manager, docs and
   all existing callers, including warmup and mutation paths.
2. Add behavior tests for whichever contract is selected. If explicit generation
   is the sole cross-builder invalidation mechanism, document it explicitly
   and avoid mixing per-builder revision into stable deployment keys.
3. Require an explicit cache-generation change to invalidate cross-builder
   entries. Do not silently add a local revision to explicit keys, because
   that would defeat current same-generation cache reuse between equivalent
   builders.

## Review boundaries and new evidence

The 2026-10-30 connected GitHub review inspected the full tracked file inventory
and the release-plan branch diff, then examined implementations and existing
coverage for the builder, repository/cache trait, resolution, compiled runtime
metadata/loading, Runwire bridge, closure serializers, workflow jobs and
CacheLayer integration. Runwire's `RequestContext`, `CancellationToken` and
`CoroutineScope`, plus CacheLayer's downstream Runwire integration, were used
to verify the optional-provider boundaries.

F7-F9 are source-level findings and targeted contract checks; **they have not
been executed as new runtime regressions**. The older local test and benchmark
evidence below belongs to the preceding audit, not to the review's exact branch
SHA. The GitHub review observed no workflow runs for `patch/runwire-3` and
no open PR with that head. Neither observation substitutes for final hosted
validation. Do not interpret inspection of all tracked paths as line-by-line
verification of every implementation.

## Review of existing edits

- `composer.json`: retain Runwire `^3.0`, CacheLayer `^4.0.2` and the updated
  suggestion. Runwire and CacheLayer remain development dependencies and optional
  consumer integrations. The ignored local lockfile already resolves the intended
  published versions; no lockfile addition is proposed.
- `README.md`, `docs/integrations.rst`, `docs/di/cache.rst`,
  `docs/benchmark.rst`, and bridge PHPDoc: retain the current Runwire 3 guidance
  and align current CacheLayer guidance with the 4.0.2 minimum.
  Historical 11.0/11.2 release and upgrade documents retain their release-specific
  compatibility statements.
- `docs/conf.py`: retaining `plans/*` in Sphinx exclusions is appropriate for this
  internal engineering plan. The public manual builds with warnings as errors.
- `.github/workflows/release-validation.yml`: retain the isolated Runwire 3
  consumer constraint and raise the isolated CacheLayer consumer constraint from
  `^4.0` to `^4.0.2`. The provider acceptance job exercises behavior through the
  full suite; the isolated consumer job separately checks installation boundaries.
- `.gitattributes` and the archive assertion loop: exclude `.aws` and assert the
  absence of local/generated payloads alongside the existing development exclusions.

## Current verification evidence

These results are **historical evidence from the preceding local audit**, taken before the
F1/F2/F7/F8/F9 implementation commits. They do not certify the current PR head.
The exact-head hosted workflow and benchmark evidence must replace them.

| Check | Current result |
| --- | --- |
| PHPForge setup | `ic:doctor`, `ic:list-config`, `ic:active-config` completed; bundled configs resolved. |
| Runtime | PHP 8.5.4; Xdebug absent; APCu and PDO SQLite available. APCu CLI explicitly enabled for the final guard and focused provider suite. |
| Direct dependency registry comparison | All six direct packages current: Runwire 3.0, CacheLayer 4.0.2, PHPForge `dev-main a6a6b41`, Opis 4.5.0, PSR cache 3.0.0 and container 2.0.2. |
| Composer validation/platform | `composer validate --strict` and `composer check-platform-reqs` passed. |
| Live dependency audit | No advisories. One development-only abandoned package; the standalone full audit exits 1 for abandonment, while PHPForge reports its configured non-blocking warning. Production-only audit exits 0 with no advisories or abandoned packages. |
| Processors | `composer ic:process` passed: Normalize, Rector, Pint and PHPCBF. Initial restricted Rector socket attempt was rerun successfully with local access. |
| Detailed quality suite | `composer ic:tests:details` passed, including 584 tests / 3,610 assertions, PHPProbe, PHPStan, Psalm security analysis, Deptrac, PHPCS, Pint and Rector. Duplicate candidates remain as F2. |
| Final release guard | `composer ic:release:guard` passed with APCu enabled: 584 tests / 3,610 assertions, runtime constraint/audit checks and quality detectors. |
| Focused providers | Runwire 3.0 / CacheLayer 4.0.2: 42 tests / 171 assertions, including dynamic, compiled and prevalidated bridges, identity propagation, cancellation/deadlines and cache behavior. |
| Component benchmarks | `composer ic:bench:quick` passed: 124 subjects, zero failures/errors, Xdebug and OPcache disabled. This is smoke evidence, not a matched sustained-RPM comparison. |
| Documentation | Sphinx 8.2.3, isolated requirements environment, 40 sources, `-W --keep-going`: passed. |
| Workflow syntax | All three tracked workflow YAML files parsed successfully. Hosted semantics remain a CI gate. |
| Composer archive | 119 files, every runtime PHP source present, no local/generated/development payloads. SHA-256: `9e7e376f37e40b22772b8dcc78afd15dc71b053b2fdb0886a947632d7722e681`. |
| Clean production consumer | Extracted archive `composer install --no-dev --classmap-authoritative` passed; value resolution and scoped-input smoke passed. Only PSR runtime dependencies installed; Runwire, CacheLayer, Opis and PHPForge absent. Its live production audit also returned no advisories or abandoned packages. |
| Runwire-only consumer | A separate production consumer installed InterMix and Runwire 3.0 without CacheLayer, Opis or PHPForge. The bridge preserved exact runtime/request identities and left request completion with the caller. |

Audit logs and temporary environments are under `/tmp/intermix-*`; they are local
supporting evidence, not durable hosted release artifacts. The archive was built
with Composer from the working tree, not `git archive HEAD`. Rebuild after any
package-content change and again from the final immutable candidate.

Source review concentrated on the provider boundary, scoped lifetime/capture and
cleanup owners, bounded/weak caches, generated artifact validation/publication,
closure authentication/deserialization, Fence and fluent helpers. Repository-wide
configured detectors and tests supply broader coverage. No additional source
vulnerability was reproduced in the reviewed boundaries. Passing tests missed F1.

## Remaining PR work and validation gates

1. **Validate implemented F1/F7/F8/F9 and finish F2 semantic triage.** Regression tests
   were committed before the source fixes. Confirm all focused tests and generated
   output on the final head, rerun the PHPProbe inventory, and classify the
   remaining duplicated-policy groups. One artifact validation duplication was
   consolidated in `CompiledResolverGenerator`; avoid speculative runtime
   abstractions. Do not close findings until CI verifies the implementation.
2. **Revalidate locally after implementation.** Run PHPForge's processor, detailed
   suite and final guard workflow. Build docs, validate workflows, rerun component
   benchmarks and compare meaningful hot-path changes with matched measurements.
   Align all current provider constraints/examples and verify Runwire 3.0 and
   CacheLayer 4.0.2 explicitly; do not expand the integration compatibility matrix.
3. **Freeze a reviewable PR candidate.** Review the complete diff and commit only
   the intended change set on the PR branch. Record the new exact PR head SHA;
   the current review branch is already committed, but no fix or new CI evidence
   has yet been recorded for F1/F7.
4. **Validate the exact PR head SHA on hosted CI.** Require PHP 8.4 and 8.5, stable/lowest
   dependency lanes, analysis, production install, documentation, release regression,
   provider acceptance, isolated consumers and Swoole/OpenSwoole scope carriers.
   Swoole/OpenSwoole and PHP 8.4 are unavailable on the current local PHP runtime.
5. **Complete representative acceptance and soak.** Preserve the configured
   concurrency curve, dynamic/production/hybrid comparisons, seven alternating
   short pairs, balanced five-minute pairs, 2% RPM budget, p99 and memory limits,
   and 30-minute worker soak. Record correctness, expected failures/cancellation,
   idle windows and artifacts. Local component checks do not close these gates.
6. **Rebuild and smoke the final distributable.** Compare its entries with tracked
   runtime files, require no local/development payloads, run an isolated `--no-dev`
   consumer and production audit, and record the final digest.
7. **Finalize the PR and hand off.** Record the final diff, resolved findings,
   exact validated PR head SHA, CI/acceptance artifacts and archive digest in the
   PR. Complete the work without merging, enabling auto-merge, tagging or
   publishing. The user will manually merge and tag 11.2.1 later. Preserve the
   existing tag workflow design where only
   `infocyph/phpforge/.github/workflows/release.yml@main` is eligible; executing it
   is outside this plan.

No hosted run was observed for the reviewed `patch/runwire-3` branch head, and no
open PR was found for that head. A later implementation commit and its exact
PR head must be rechecked. Historical local measurements and run IDs are not
proof of the final candidate's green CI or acceptance performance.

Stop for any new advisory, identity/ownership/isolation regression, unexpected
production dependency, archive leak, wrong output or resource-budget breach. For
a single noisy performance outlier, rerun the unchanged exact SHA and inspect
matched evidence before proposing a code change; preserve existing budgets.

**Status: draft PR #146 opened; F1/F7/F8/F9 root-cause changes and regression
coverage committed, with provider minimum and docs aligned. F2 remains partly
triaged. Exact-head PHPForge, provider, performance, soak, archive and compatibility
CI are mandatory before declaring release readiness. Do not merge, tag or publish.**
