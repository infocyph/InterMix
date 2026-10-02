<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;
use RuntimeException;
use WeakReference;

final class CandidateReadinessMethodProbe
{
    #[Inject(value: 'attribute')]
    public function __invoke(string $value = 'default'): string
    {
        return $value;
    }

    #[Inject(value: 'attribute')]
    public function run(string $value = 'default'): string
    {
        return $value;
    }

    #[Inject(value: 'static-attribute')]
    public static function staticRun(string $value = 'default'): string
    {
        return $value;
    }
}

function candidateReadinessArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-candidate-readiness-' . bin2hex(random_bytes(8)) . '.php';
}

function candidateReadinessRemoveArtifact(?string $path): void
{
    if ($path === null) {
        return;
    }

    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

/** @return array{RuntimeContainerInterface, string|null} */
function candidateReadinessRuntime(ContainerBuilder $builder, bool $production): array
{
    if (!$production) {
        return [$builder->build(), null];
    }

    $path = candidateReadinessArtifactPath();
    $builder->releaseIdentity('candidate-readiness')->compile($path);

    return [$builder->production($path), $path];
}

function candidateReadinessAssertFullRecovery(bool $production, bool $fiber): void
{
    $leaves = [];
    $builder = ContainerBuilder::create(uniqid('candidate-recovery-', true));
    foreach (['outer', 'inner'] as $scope) {
        $builder->onScopeLeave($scope, static function (string $left) use (&$leaves): void {
            $leaves[] = $left;
            throw new RuntimeException('cleanup-' . $left);
        });
    }
    [$runtime, $path] = candidateReadinessRuntime($builder, $production);

    try {
        $work = static function () use ($runtime, &$leaves): array {
            testEnterScope($runtime, 'outer');
            testEnterScope($runtime, 'inner');

            $failure = null;
            try {
                $runtime->resetCurrentExecutionScope();
            } catch (ScopeCleanupException $exception) {
                $failure = $exception;
            }

            testEnterScope($runtime, 'fresh');
            testLeaveScope($runtime);

            return [$failure, $leaves];
        };

        [$failure, $observed] = $fiber
            ? (static function () use ($work): array {
                $fiber = new Fiber($work);
                $fiber->start();

                return $fiber->getReturn();
            })()
            : $work();

        expect($failure)->toBeInstanceOf(ScopeCleanupException::class)
            ->and($failure->cleanupFailureCount)->toBe(2)
            ->and($observed)->toBe(['inner', 'outer']);
    } finally {
        candidateReadinessRemoveArtifact($path);
    }
}

function candidateReadinessAssertDrainingAdmission(bool $production): void
{
    $builder = ContainerBuilder::create(uniqid('candidate-draining-', true));
    [$runtime, $path] = candidateReadinessRuntime($builder, $production);

    try {
        $context = null;
        $child = null;

        $ownerFailure = null;
        try {
            $runtime->withinScope('owner', static function ($active) use (&$context, &$child): void {
                $context = $active->captureScopeContext();
                $child = new Fiber(static fn() => $active->withinScopeContext(
                    $context,
                    static function (): void {
                        Fiber::suspend();
                    },
                ));
                $child->start();
            });
        } catch (ContainerException $exception) {
            $ownerFailure = $exception;
        }

        expect($ownerFailure)->toBeInstanceOf(ContainerException::class)
            ->and($ownerFailure->getMessage())->toContain('child execution carriers are still attached')
            ->and($context)->not->toBeNull()
            ->and($child)->toBeInstanceOf(Fiber::class);

        $late = new Fiber(static fn() => $runtime->withinScopeContext(
            $context,
            static fn() => 'unexpected',
        ));
        expect(fn() => $late->start())
            ->toThrow(ContainerException::class, 'draining');

        $child->resume();
        $runtime->resetCurrentExecutionScope();

        expect(fn() => $runtime->withinScopeContext($context, static fn() => 'stale'))
            ->toThrow(ContainerException::class);
    } finally {
        candidateReadinessRemoveArtifact($path);
    }
}

function candidateReadinessAssertReleasedPayload(bool $production): void
{
    $builder = ContainerBuilder::create(uniqid('candidate-release-', true))
        ->input('request');
    [$runtime, $path] = candidateReadinessRuntime($builder, $production);

    try {
        $seed = new stdClass();
        $weak = WeakReference::create($seed);
        $iterator = $runtime->withinScope(
            'request',
            static fn($active) => $active->tagged('empty'),
            ['request' => $seed],
        );

        unset($seed);
        gc_collect_cycles();

        expect($iterator)->toBeIterable()
            ->and($weak->get())->toBeNull();

        $seed = new stdClass();
        $weak = WeakReference::create($seed);
        $context = $runtime->withinScope(
            'request',
            static fn($active) => $active->captureScopeContext(),
            ['request' => $seed],
        );

        unset($seed);
        gc_collect_cycles();

        expect($context)->not->toBeNull()
            ->and($weak->get())->toBeNull();
    } finally {
        candidateReadinessRemoveArtifact($path);
    }
}

it('drains every safely closable dynamic frame after multiple cleanup failures', function () {
    candidateReadinessAssertFullRecovery(false, false);
    candidateReadinessAssertFullRecovery(false, true);
});

it('drains every safely closable production frame after multiple cleanup failures', function () {
    candidateReadinessAssertFullRecovery(true, false);
    candidateReadinessAssertFullRecovery(true, true);
});

it('releases closed dynamic scope payloads while stale handles stay alive', function () {
    candidateReadinessAssertReleasedPayload(false);
});

it('releases closed production scope payloads while stale handles stay alive', function () {
    candidateReadinessAssertReleasedPayload(true);
});

it('rejects new dynamic attachments after owner close enters recovery', function () {
    candidateReadinessAssertDrainingAdmission(false);
});

it('rejects new production attachments after owner close enters recovery', function () {
    candidateReadinessAssertDrainingAdmission(true);
});

it('applies method-level Inject consistently across callable forms and supplied precedence', function () {
    foreach ([false, true] as $production) {
        $builder = ContainerBuilder::create(uniqid('candidate-method-attrs-', true))
            ->enableMethodAttributes(true);
        [$runtime, $path] = candidateReadinessRuntime($builder, $production);

        try {
            $target = new CandidateReadinessMethodProbe();

            expect($runtime->invoke([$target, 'run']))->toBe('attribute')
                ->and($runtime->invoke([CandidateReadinessMethodProbe::class, 'staticRun']))->toBe('static-attribute')
                ->and($runtime->invoke($target))->toBe('attribute')
                ->and($runtime->invoke($target->run(...)))->toBe('attribute')
                ->and($runtime->invoke([$target, 'run'], ['value' => 'supplied']))->toBe('supplied');
        } finally {
            candidateReadinessRemoveArtifact($path);
        }
    }
});
