<?php

declare(strict_types=1);

use Fiber;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use InvalidArgumentException;
use stdClass;
use WeakReference;

final class RuntimeHardeningSuspendingDependency
{
    public function __construct()
    {
        Fiber::suspend('dependency');
    }
}

it('isolates definition and class construction ancestry between independent fibers', function (): void {
    $container = new ConfigurationContainer('p1.concurrent');
    $container->bind(
        'service',
        static fn(RuntimeHardeningSuspendingDependency $dependency): RuntimeHardeningSuspendingDependency
            => $dependency,
        LifetimeEnum::Transient,
    );

    $resolve = static fn(): RuntimeHardeningSuspendingDependency => $container->get('service');
    $first = new Fiber($resolve);
    $second = new Fiber($resolve);

    expect($first->start())->toBe('dependency')
        ->and($second->start())->toBe('dependency');

    $first->resume();
    $second->resume();

    expect($first->getReturn())->toBeInstanceOf(RuntimeHardeningSuspendingDependency::class)
        ->and($second->getReturn())->toBeInstanceOf(RuntimeHardeningSuspendingDependency::class)
        ->and($first->getReturn())->not->toBe($second->getReturn());
});

it('still rejects a real same-carrier definition cycle', function (): void {
    $container = new ConfigurationContainer('p1.cycle');
    $container->bind(
        'cycle',
        static fn() => $container->get('cycle'),
        LifetimeEnum::Transient,
    );

    expect(fn() => $container->get('cycle'))
        ->toThrow(ContainerException::class, "Circular dependency for definition 'cycle'.");
});

it('fails fast when another carrier is constructing the same singleton', function (): void {
    $container = new ConfigurationContainer('p1.singleton');
    $container->bindFactory(
        'singleton',
        static function (): object {
            Fiber::suspend('singleton');

            return new stdClass();
        },
    );

    $first = new Fiber(static fn(): object => $container->get('singleton'));
    $second = new Fiber(static fn(): object => $container->get('singleton'));

    expect($first->start())->toBe('singleton')
        ->and(fn() => $second->start())->toThrow(
            ContainerException::class,
            "Singleton service 'singleton' is already being constructed by another execution carrier.",
        );

    $first->resume();

    expect($first->getReturn())->toBeInstanceOf(stdClass::class);
});

it('does not retain the final completed fiber through the carrier fast path', function (): void {
    $payloadReference = null;
    $fiber = new Fiber(static function () use (&$payloadReference): object {
        ExecutionContext::id();
        $payload = new stdClass();
        $payloadReference = WeakReference::create($payload);

        return $payload;
    });

    $fiber->start();
    expect($fiber->isTerminated())->toBeTrue();

    $fiberReference = WeakReference::create($fiber);
    unset($fiber);
    gc_collect_cycles();
    ExecutionContext::id();
    gc_collect_cycles();

    expect($fiberReference->get())->toBeNull()
        ->and($payloadReference)->toBeInstanceOf(WeakReference::class)
        ->and($payloadReference->get())->toBeNull();
});

it('rejects cyclic cache values without changing the in-process result', function (): void {
    $cache = Cache::memory('p1.cyclic.' . bin2hex(random_bytes(4)));
    $container = new ConfigurationContainer('p1.cyclic');
    $container->definitions()->enableDefinitionCache($cache, 'p1-cyclic');
    $container->bind('value', static function (): array {
        $value = [];
        $value['self'] = &$value;

        return $value;
    });

    $resolved = $container->get('value');
    $key = $container->getRepository()->makeDefinitionCacheKey('value');

    expect($resolved)->toBeArray()
        ->and(array_key_exists('self', $resolved))->toBeTrue()
        ->and($cache->hasItem($key))->toBeFalse();
});

it('bounds cache and exportability traversal by depth and work', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 66; ++$i) {
        $deep = [$deep];
    }

    expect(BoundedValueInspector::isScalarNullArray(['safe' => [1, null, false, 'value']]))->toBeTrue()
        ->and(BoundedValueInspector::isScalarNullArray($deep))->toBeFalse()
        ->and(BoundedValueInspector::isScalarNullArray(range(1, 101), maxValues: 100))->toBeFalse();
});

it('rejects cyclic declarative factory arguments with a controlled exception', function (): void {
    $cyclic = [];
    $cyclic['self'] = &$cyclic;

    expect(fn() => FactoryDefinition::construct(stdClass::class, [$cyclic]))
        ->toThrow(
            InvalidArgumentException::class,
            'Declarative factory arguments must be service references or exportable values.',
        );
});
