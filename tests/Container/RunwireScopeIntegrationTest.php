<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

final class RunwireIntegrationScopedLeaf {}

function runwireIntegrationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-runwire-' . bin2hex(random_bytes(8)) . '.php';
}

function removeRunwireIntegrationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('shares one dynamic logical scope through Runwire task-local snapshots', function () {
    $container = new Container(uniqid('runwire_dynamic_'));
    $container->scoped('leaf', RunwireIntegrationScopedLeaf::class);
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();
    $scopeLocal = new TaskLocal();

    $resolved = new CoroutineRuntime()->run(
        static function (CoroutineScope $scope) use ($container, $context, $scopeLocal): array {
            $scope->setLocal($scopeLocal, $context);

            $spawn = static function () use ($container, $scope, $scopeLocal): Task {
                return $scope->spawn(static function () use ($container, $scope, $scopeLocal): object {
                    $captured = $scope->local($scopeLocal);
                    if (!$captured instanceof ScopeContext) {
                        throw new RuntimeException('Runwire task-local scope context was not inherited.');
                    }

                    return $container->withinScopeContext(
                        $captured,
                        static fn(Container $active): object => $active->get('leaf'),
                    );
                });
            };

            $first = $spawn();
            $second = $spawn();

            return [$first->await(), $second->await()];
        },
    );

    expect($resolved[0])->toBe($parent)
        ->and($resolved[1])->toBe($parent);

    $container->leaveScope();
});

it('keeps compiled Runwire child frames carrier-local while restoring the shared parent', function () {
    $builder = ContainerBuilder::create(uniqid('runwire_compiled_'));
    $builder->scoped('leaf', RunwireIntegrationScopedLeaf::class);

    $path = runwireIntegrationArtifactPath();
    try {
        $builder->compile($path);
        $container = $builder->production($path);
        $container->enterScope('request');
        $parent = $container->get('leaf');
        $context = $container->captureScopeContext();
        $scopeLocal = new TaskLocal();

        $resolved = new CoroutineRuntime()->run(
            static function (CoroutineScope $scope) use ($container, $context, $scopeLocal): array {
                $scope->setLocal($scopeLocal, $context);

                $spawn = static function () use ($container, $scope, $scopeLocal): Task {
                    return $scope->spawn(static function () use ($container, $scope, $scopeLocal): array {
                        $captured = $scope->local($scopeLocal);
                        if (!$captured instanceof ScopeContext) {
                            throw new RuntimeException('Runwire task-local scope context was not inherited.');
                        }

                        return $container->withinScopeContext(
                            $captured,
                            static function (ProductionContainer $active) use ($scope): array {
                                $active->enterScope('nested');
                                $nested = $active->get('leaf');
                                $scope->yieldNow();
                                $active->leaveScope();

                                return [$nested, $active->get('leaf')];
                            },
                        );
                    });
                };

                $first = $spawn();
                $second = $spawn();

                return [$first->await(), $second->await()];
            },
        );

        expect($resolved[0][0])->not->toBe($parent)
            ->and($resolved[1][0])->not->toBe($parent)
            ->and($resolved[0][0])->not->toBe($resolved[1][0])
            ->and($resolved[0][1])->toBe($parent)
            ->and($resolved[1][1])->toBe($parent);

        $container->leaveScope();
    } finally {
        removeRunwireIntegrationArtifact($path);
    }
});

it('releases attached child scopes when Runwire fail-fast cancels a sibling', function (): void {
    $container = new Container(uniqid('runwire_fail_fast_'));
    $container->scoped('leaf', RunwireIntegrationScopedLeaf::class);
    $nestedLeaves = 0;
    $container->onScopeLeave('nested', static function () use (&$nestedLeaves): void {
        ++$nestedLeaves;
    });
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();
    $scopeLocal = new TaskLocal();
    $runtime = new CoroutineRuntime();
    $caught = null;

    try {
        $runtime->run(static function (CoroutineScope $scope) use ($container, $context, $scopeLocal): void {
            $scope->setLocal($scopeLocal, $context);
            $scope->spawn(static function () use ($container, $scope, $scopeLocal): void {
                $captured = $scope->local($scopeLocal);
                if (!$captured instanceof ScopeContext) {
                    throw new RuntimeException('Runwire task-local scope context was not inherited.');
                }

                $container->withinScopeContext(
                    $captured,
                    static function (Container $active) use ($scope): void {
                        $active->enterScope('nested');
                        $active->get('leaf');
                        $scope->sleep(30.0);
                    },
                );
            });
            $scope->spawn(static function () use ($scope): void {
                $scope->yieldNow();
                throw new RuntimeException('runwire-fail-fast');
            });
        });
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught?->getMessage())->toBe('runwire-fail-fast')
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($nestedLeaves)->toBe(1)
        ->and($container->get('leaf'))->toBe($parent);

    $container->leaveScope();
});

it('releases an attached nested scope after explicit Runwire task cancellation', function (): void {
    $container = new Container(uniqid('runwire_explicit_cancel_'));
    $container->scoped('leaf', RunwireIntegrationScopedLeaf::class);
    $nestedLeaves = 0;
    $container->onScopeLeave('nested', static function () use (&$nestedLeaves): void {
        ++$nestedLeaves;
    });
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();
    $scopeLocal = new TaskLocal();
    $runtime = new CoroutineRuntime();

    $reason = $runtime->run(static function (CoroutineScope $scope) use ($container, $context, $scopeLocal): CancellationReason {
        $scope->setLocal($scopeLocal, $context);
        $task = $scope->spawn(static function () use ($container, $scope, $scopeLocal): void {
            $captured = $scope->local($scopeLocal);
            if (!$captured instanceof ScopeContext) {
                throw new RuntimeException('Runwire task-local scope context was not inherited.');
            }

            $container->withinScopeContext(
                $captured,
                static function (Container $active) use ($scope): void {
                    $active->enterScope('nested');
                    $active->get('leaf');
                    $scope->sleep(30.0);
                },
            );
        });
        $scope->yieldNow();
        $task->cancel();

        try {
            $task->await();
        } catch (CancelledException $error) {
            return $error->reason;
        }

        throw new RuntimeException('Expected the Runwire child task to be cancelled.');
    });

    expect($reason)->toBe(CancellationReason::HOST_CANCELLED)
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($nestedLeaves)->toBe(1)
        ->and($container->get('leaf'))->toBe($parent);

    $container->leaveScope();
});

it('releases attached scopes when a Runwire deadline expires', function (): void {
    $container = new Container(uniqid('runwire_deadline_'));
    $container->scoped('leaf', RunwireIntegrationScopedLeaf::class);
    $nestedLeaves = 0;
    $container->onScopeLeave('nested', static function () use (&$nestedLeaves): void {
        ++$nestedLeaves;
    });
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();
    $scopeLocal = new TaskLocal();
    $runtime = new CoroutineRuntime();
    $caught = null;

    try {
        $runtime->run(static function (CoroutineScope $scope) use ($container, $context, $scopeLocal): void {
            $scope->setLocal($scopeLocal, $context);
            $clock = hrtime(true);
            $now = is_int($clock) ? $clock : (int) $clock;

            $scope->withDeadline(
                new RequestDeadline($now + 50_000_000),
                static function (CoroutineScope $inner) use ($container, $scopeLocal): void {
                    $inner->spawn(static function () use ($container, $inner, $scopeLocal): void {
                        $captured = $inner->local($scopeLocal);
                        if (!$captured instanceof ScopeContext) {
                            throw new RuntimeException('Runwire task-local scope context was not inherited.');
                        }

                        $container->withinScopeContext(
                            $captured,
                            static function (Container $active) use ($inner): void {
                                $active->enterScope('nested');
                                $active->get('leaf');
                                $inner->sleep(30.0);
                            },
                        );
                    });
                    $inner->sleep(30.0);
                },
            );
        });
    } catch (CancelledException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(CancelledException::class)
        ->and($caught?->reason)->toBe(CancellationReason::DEADLINE_EXCEEDED)
        ->and($runtime->activeTaskCount())->toBe(0)
        ->and($nestedLeaves)->toBe(1)
        ->and($container->get('leaf'))->toBe($parent);

    $container->leaveScope();
});
