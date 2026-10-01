<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;
use Psr\Container\NotFoundExceptionInterface;

final class Runtime11Dependency {}

final readonly class Runtime11Service
{
    public function __construct(public Runtime11Dependency $dependency) {}
}

final class Runtime11Scoped {}

final readonly class Runtime11Captive
{
    public function __construct(public Runtime11Scoped $scoped) {}
}

/** @return array{RuntimeContainerInterface, callable(): void} */
function runtime11Production(ContainerBuilder $builder): array
{
    $path = sys_get_temp_dir() . '/intermix-runtime11-' . uniqid('', true) . '.php';
    $builder->compile($path);
    $runtime = $builder->production($path);

    return [$runtime, static function () use ($path): void {
        if (is_file($path)) { unlink($path); }
        if (is_file($path . '.meta.json')) { unlink($path . '.meta.json'); }
    }];
}

test('dynamic runtime exposes the canonical execution vocabulary', function () {
    $runtime = ContainerBuilder::create()
        ->autowire(Runtime11Dependency::class, Runtime11Dependency::class)
        ->autowire(Runtime11Service::class, Runtime11Service::class)
        ->build();

    expect($runtime)->toBeInstanceOf(RuntimeContainerInterface::class)
        ->and($runtime->has(Runtime11Service::class))->toBeTrue()
        ->and($runtime->has(stdClass::class))->toBeFalse()
        ->and($runtime->get(Runtime11Service::class))->toBeInstanceOf(Runtime11Service::class)
        ->and($runtime->make(Runtime11Service::class))->toBeInstanceOf(Runtime11Service::class)
        ->and($runtime->make(Runtime11Service::class))->not->toBe($runtime->make(Runtime11Service::class))
        ->and($runtime->invoke(static fn(Runtime11Dependency $dependency): object => $dependency))
        ->toBeInstanceOf(Runtime11Dependency::class);
});

test('declared failures are container errors while absent requested IDs are not found', function () {
    $runtime = ContainerBuilder::create()
        ->factory('broken', static fn(RuntimeContainerInterface $runtime): mixed => $runtime->get('missing'))
        ->factory('user-not-found', static fn(): mixed => throw new NotFoundException('factory'))
        ->build();

    try {
        $runtime->get('missing');
        test()->fail('Expected missing ID failure.');
    } catch (Throwable $throwable) {
        expect($throwable)->toBeInstanceOf(NotFoundExceptionInterface::class);
    }

    foreach (['broken', 'user-not-found'] as $id) {
        try {
            $runtime->get($id);
            test()->fail("Expected '$id' to fail.");
        } catch (Throwable $throwable) {
            expect($throwable)->toBeInstanceOf(ContainerException::class)
                ->not->toBeInstanceOf(NotFoundExceptionInterface::class)
                ->and($throwable->getPrevious())->toBeInstanceOf(NotFoundExceptionInterface::class);
        }
    }
});

test('scoped entries require a frame and seeds can only override declared scoped entries', function () {
    $runtime = ContainerBuilder::create()
        ->autowire(Runtime11Scoped::class, Runtime11Scoped::class, lifetime: LifetimeEnum::Scoped)
        ->input('request.id')
        ->value('singleton', new stdClass())
        ->build();

    expect(fn() => $runtime->get(Runtime11Scoped::class))->toThrow(ContainerException::class)
        ->and(fn() => $runtime->withinScope('request', static fn() => null, ['singleton' => new stdClass()]))
        ->toThrow(ContainerException::class);

    $seed = new Runtime11Scoped();
    $result = $runtime->withinScope(
        'request',
        static fn(RuntimeContainerInterface $active): array => [
            $active->get(Runtime11Scoped::class),
            $active->get('request.id'),
        ],
        [Runtime11Scoped::class => $seed, 'request.id' => 'abc'],
    );

    expect($result)->toBe([$seed, 'abc']);
});

test('singleton construction cannot capture scoped values even after warming a seed', function () {
    $runtime = ContainerBuilder::create()
        ->autowire(Runtime11Scoped::class, Runtime11Scoped::class, lifetime: LifetimeEnum::Scoped)
        ->factory(
            'captive',
            static fn(RuntimeContainerInterface $runtime): object => new Runtime11Captive(
                $runtime->get(Runtime11Scoped::class),
            ),
        )
        ->build();

    $runtime->withinScope('request', function (RuntimeContainerInterface $active): void {
        $active->get(Runtime11Scoped::class);
        expect(fn() => $active->get('captive'))->toThrow(ContainerException::class);
    });
});

test('builder rejects statically visible captive dependency graphs', function () {
    $builder = ContainerBuilder::create()
        ->autowire(Runtime11Scoped::class, Runtime11Scoped::class, lifetime: LifetimeEnum::Scoped)
        ->autowire(Runtime11Captive::class, Runtime11Captive::class);

    expect(fn() => $builder->build())->toThrow(ContainerException::class);
});

test('tagged iteration is lazy and tied to its originating scope', function () {
    $calls = 0;
    $runtime = ContainerBuilder::create()
        ->factory('tagged.one', static function () use (&$calls): object {
            ++$calls;

            return new stdClass();
        }, LifetimeEnum::Scoped, ['workers'])
        ->build();

    $iterator = $runtime->withinScope('request', function (RuntimeContainerInterface $active) use (&$calls): iterable {
        $iterator = $active->tagged('workers');
        expect($calls)->toBe(0);

        return $iterator;
    });

    expect(fn() => iterator_to_array($iterator))->toThrow(ContainerException::class)
        ->and($calls)->toBe(0);

    $runtime->withinScope('request', function (RuntimeContainerInterface $active) use (&$calls): void {
        $values = iterator_to_array($active->tagged('workers'));
        expect($values)->toHaveKey('tagged.one')
            ->and($values['tagged.one'])->toBeInstanceOf(stdClass::class)
            ->and($calls)->toBe(1);
    });
});

test('scope cleanup runs every hook and reports bounded compound failure data', function () {
    $builder = ContainerBuilder::create();
    for ($index = 0; $index < 35; ++$index) {
        $builder->onScopeLeave('request', static fn() => throw new RuntimeException('cleanup'));
    }
    $runtime = $builder->build();
    $work = new LogicException('work');

    try {
        $runtime->withinScope('request', static fn() => throw $work);
        test()->fail('Expected compound cleanup failure.');
    } catch (ScopeCleanupException $exception) {
        expect($exception->cleanupFailureCount)->toBe(35)
            ->and($exception->cleanupFailures)->toHaveCount(32)
            ->and($exception->getPrevious())->toBe($work);
    }

    expect(fn() => $runtime->withinScope('request', static fn(): string => 'recovered'))
        ->toThrow(ScopeCleanupException::class);
});

test('production runtime implements canonical invocation and strict scope behavior', function () {
    $builder = ContainerBuilder::create()
        ->autowire(Runtime11Dependency::class, Runtime11Dependency::class)
        ->autowire(Runtime11Service::class, Runtime11Service::class)
        ->autowire(Runtime11Scoped::class, Runtime11Scoped::class, lifetime: LifetimeEnum::Scoped, tags: ['workers']);
    [$runtime, $cleanup] = runtime11Production($builder);

    try {
        expect($runtime)->toBeInstanceOf(ProductionContainer::class)
            ->and($runtime)->toBeInstanceOf(RuntimeContainerInterface::class)
            ->and($runtime->invoke(static fn(Runtime11Dependency $dependency): object => $dependency))
            ->toBeInstanceOf(Runtime11Dependency::class)
            ->and(fn() => $runtime->get(Runtime11Scoped::class))
            ->toThrow(ContainerException::class);

        $runtime->withinScope('request', function (RuntimeContainerInterface $active): void {
            expect(iterator_to_array($active->tagged('workers'))[Runtime11Scoped::class])
                ->toBeInstanceOf(Runtime11Scoped::class);
        });
    } finally {
        $cleanup();
    }
});

test('removed runtime and ownership APIs are absent from public runtimes', function () {
    foreach ([Container::class, ProductionContainer::class] as $class) {
        $reflection = new ReflectionClass($class);
        foreach (['call', 'getReturn', 'resolveNow', 'parseCallable', 'enterScope', 'leaveScope'] as $method) {
            expect($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic())
                ->toBeFalse();
        }
    }

    expect(method_exists(Container::class, 'instance'))->toBeFalse()
        ->and(method_exists(Container::class, 'unset'))->toBeFalse()
        ->and(class_exists('Infocyph\\InterMix\\DI\\Invoker'))->toBeFalse();
});
