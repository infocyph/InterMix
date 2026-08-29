<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

uses()->group('di', 'performance-regression');

final class DiHotPathCompiledDependency {}

final readonly class DiHotPathCompiledService
{
    public function __construct(public DiHotPathCompiledDependency $dependency) {}
}

interface DiHotPathActivatedContract {}

final class DiHotPathActivatedService implements DiHotPathActivatedContract {}

it('activates compiled resolution after the dynamic resolver was already materialized', function () {
    $path = sys_get_temp_dir() . '/intermix-hot-path-' . uniqid('', true) . '.php';
    $container = new Container(uniqid('hot_path_compiled_', true));
    $container->bind('service', DiHotPathCompiledService::class, LifetimeEnum::Transient);

    expect($container->getCurrentResolver())->toBeInstanceOf(InjectedCall::class);
    $container->get('service');
    $container->compileTo($path, load: true);

    try {
        expect($container->getCurrentResolver())->toBeInstanceOf(CompiledCall::class)
            ->and($container->get('service'))->toBeInstanceOf(DiHotPathCompiledService::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('preserves lifetimes registered by missing-service activation', function () {
    $scoped = new Container(uniqid('hot_path_scoped_', true));
    $scoped->onMissing(function (string $id, Container $container): void {
        if ($id === DiHotPathActivatedContract::class) {
            $container->scoped($id, DiHotPathActivatedService::class);
        }
    });
    $scoped->enterScope('one');
    $first = $scoped->get(DiHotPathActivatedContract::class);
    $firstAgain = $scoped->get(DiHotPathActivatedContract::class);
    $scoped->leaveScope()->enterScope('two');
    $second = $scoped->get(DiHotPathActivatedContract::class);
    $scoped->leaveScope();

    $transient = new Container(uniqid('hot_path_transient_', true));
    $transient->onMissing(function (string $id, Container $container): void {
        if ($id === DiHotPathActivatedContract::class) {
            $container->transient($id, DiHotPathActivatedService::class);
        }
    });

    expect($first)->toBe($firstAgain)
        ->and($second)->not->toBe($first)
        ->and($transient->get(DiHotPathActivatedContract::class))
        ->not->toBe($transient->get(DiHotPathActivatedContract::class));
});

it('keeps null singleton values on the cached hot path', function () {
    $container = new Container(uniqid('hot_path_null_', true));
    $calls = 0;
    $container->singleton('nullable', function () use (&$calls): null {
        ++$calls;

        return null;
    });

    expect($container->get('nullable'))->toBeNull()
        ->and($container->get('nullable'))->toBeNull()
        ->and($calls)->toBe(1);
});

it('does not invalidate resolved state when the compatibility lazy flag changes', function () {
    $container = new Container(uniqid('hot_path_lazy_', true));
    $calls = 0;
    $container->singleton('service', function () use (&$calls): stdClass {
        ++$calls;

        return new stdClass();
    });

    $service = $container->get('service');
    $container->enableLazyLoading(false)->enableLazyLoading(true);

    expect($container->get('service'))->toBe($service)
        ->and($calls)->toBe(1);
});

it('registers definition metadata and tags atomically', function () {
    $container = new Container(uniqid('hot_path_atomic_', true));
    $container->bind(
        'tagged',
        static fn(): stdClass => new stdClass(),
        LifetimeEnum::Scoped,
        ['hot', 'request'],
    );

    $meta = $container->getRepository()->getDefinitionMeta('tagged');

    expect($meta['lifetime'])->toBe(LifetimeEnum::Scoped)
        ->and($meta['tags'])->toBe(['hot', 'request'])
        ->and($container->getRepository()->getIdsByTag('hot'))->toContain('tagged');
});
