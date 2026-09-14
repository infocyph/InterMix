<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;

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

            $spawn = static function () use ($container, $scope, $scopeLocal): object {
                $task = $scope->spawn(static function () use ($container, $scope, $scopeLocal): object {
                    $captured = $scope->local($scopeLocal);
                    if (!$captured instanceof ScopeContext) {
                        throw new RuntimeException('Runwire task-local scope context was not inherited.');
                    }

                    return $container->withinScopeContext(
                        $captured,
                        static fn(Container $active): object => $active->get('leaf'),
                    );
                });

                return $task;
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

                $spawn = static function () use ($container, $scope, $scopeLocal) {
                    return $scope->spawn(static function () use ($container, $scope, $scopeLocal): array {
                        $captured = $scope->local($scopeLocal);
                        if (!$captured instanceof ScopeContext) {
                            throw new RuntimeException('Runwire task-local scope context was not inherited.');
                        }

                        return $container->withinScopeContext(
                            $captured,
                            static function ($active) use ($scope): array {
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
