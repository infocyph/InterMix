<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;

uses()->group('di', 'missing-resolution');

interface MissingResolutionContract {}

final class MissingResolutionService implements MissingResolutionContract {}

final class MissingResolutionMiddle
{
    public function __construct(public MissingResolutionContract $service) {}
}

final class MissingResolutionRoot
{
    public function __construct(public MissingResolutionMiddle $middle) {}
}

final class MissingResolutionMethodTarget
{
    public function handle(MissingResolutionContract $service): MissingResolutionContract
    {
        return $service;
    }
}

final class MissingResolutionPropertyTarget
{
    #[Inject]
    public MissingResolutionContract $service;
}

final class MissingResolutionNamedPropertyTarget
{
    #[Inject('missing.named')]
    public mixed $service = null;
}

final class MissingResolutionCompiledProduct
{
    public function __construct(public MissingResolutionContract $service) {}
}

interface MissingResolutionFirst {}

interface MissingResolutionSecond {}

it('activates direct get, make, and call requests', function () {
    foreach (['get', 'make', 'call'] as $operation) {
        $container = new Container();
        $seen = [];
        $container->onMissing(function (string $id, Container $container) use (&$seen): void {
            $seen[] = $id;
            if ($id === MissingResolutionContract::class) {
                $container->singleton(MissingResolutionContract::class, MissingResolutionService::class);
            }
        });

        $resolved = $container->{$operation}(MissingResolutionContract::class);

        expect($resolved)->toBeInstanceOf(MissingResolutionService::class)
            ->and($seen)->toBe([MissingResolutionContract::class]);
    }
});

it('activates nested constructor and method dependencies', function () {
    $container = new Container();
    $container->onMissing(function (string $id, Container $container): void {
        if ($id === MissingResolutionContract::class) {
            $container->singleton(MissingResolutionContract::class, MissingResolutionService::class);
        }
    });

    $root = $container->get(MissingResolutionRoot::class);
    $fromMethod = $container->call(MissingResolutionMethodTarget::class, 'handle');

    expect($root->middle->service)->toBeInstanceOf(MissingResolutionService::class)
        ->and($fromMethod)->toBe($root->middle->service);
});

it('activates type-based and explicitly named Inject properties', function () {
    $container = new Container();
    $container->options()->setOptions(propertyAttributes: true);
    $container->onMissing(function (string $id, Container $container): void {
        if ($id === MissingResolutionContract::class) {
            $container->singleton(MissingResolutionContract::class, MissingResolutionService::class);
        }
        if ($id === 'missing.named') {
            $container->value('missing.named', 'activated');
        }
    });

    $typed = $container->get(MissingResolutionPropertyTarget::class);
    $named = $container->get(MissingResolutionNamedPropertyTarget::class);

    expect($typed->service)->toBeInstanceOf(MissingResolutionService::class)
        ->and($named->service)->toBe('activated');
});

it('does not run hooks for normally resolvable entries', function () {
    $container = new Container();
    $calls = 0;
    $container->onMissing(function () use (&$calls): void {
        $calls++;
    });
    $container->setEnvironment('test');
    $container->options()->bindInterfaceForEnv(
        'test',
        MissingResolutionContract::class,
        MissingResolutionService::class,
    );

    expect($container->get(MissingResolutionService::class))->toBeInstanceOf(MissingResolutionService::class)
        ->and($container->get(MissingResolutionContract::class))->toBeInstanceOf(MissingResolutionService::class)
        ->and($calls)->toBe(0);
});

it('runs hooks in order, stops after activation, and preserves lifecycle order', function () {
    $container = new Container();
    $events = [];
    $container->onMissing(function (string $id) use (&$events): void {
        $events[] = "missing:first:$id";
    });
    $container->onMissing(function (string $id, Container $container) use (&$events): void {
        $events[] = "missing:second:$id";
        $container->transient($id, MissingResolutionService::class);
    });
    $container->onMissing(function () use (&$events): void {
        $events[] = 'missing:too-late';
    });
    $container->onResolving(MissingResolutionContract::class, function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $container->onResolved(MissingResolutionContract::class, function (string $id) use (&$events): void {
        $events[] = "resolved:$id";
    });

    $container->get(MissingResolutionContract::class);

    expect($events)->toBe([
        'missing:first:' . MissingResolutionContract::class,
        'missing:second:' . MissingResolutionContract::class,
        'resolving:' . MissingResolutionContract::class,
        'resolved:' . MissingResolutionContract::class,
    ]);
});

it('does not negatively cache failed activation attempts', function () {
    $container = new Container();
    $calls = 0;
    $container->onMissing(function () use (&$calls): void {
        $calls++;
    });

    foreach ([1, 2] as $_) {
        try {
            $container->get('still.missing');
        } catch (NotFoundException) {
        }
    }

    expect($calls)->toBe(2);
});

it('propagates hook failures unchanged and releases the recursion guard', function () {
    $container = new Container();
    $failure = new DomainException('activation failed');
    $calls = 0;
    $container->onMissing(function () use ($failure, &$calls): void {
        $calls++;
        if ($calls === 1) {
            throw $failure;
        }
    });

    $caught = null;
    try {
        $container->get('missing.failure');
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    expect($caught)->toBe($failure)
        ->and(fn() => $container->get('missing.failure'))->toThrow(NotFoundException::class)
        ->and($calls)->toBe(2);
});

it('guards recursion per ID while allowing different missing IDs to activate', function () {
    $container = new Container();
    $seen = [];
    $container->onMissing(function (string $id, Container $container) use (&$seen): void {
        $seen[] = $id;
        if ($id === MissingResolutionFirst::class) {
            $container->get(MissingResolutionSecond::class);
            $container->value(MissingResolutionFirst::class, 'first');

            return;
        }
        if ($id === MissingResolutionSecond::class) {
            try {
                $container->get(MissingResolutionFirst::class);
            } catch (NotFoundException) {
            }
            $container->value(MissingResolutionSecond::class, 'second');
        }
    });

    expect($container->get(MissingResolutionFirst::class))->toBe('first')
        ->and($container->get(MissingResolutionSecond::class))->toBe('second')
        ->and($seen)->toBe([
            MissingResolutionFirst::class,
            MissingResolutionSecond::class,
        ]);
});

it('preserves singleton and scoped lifetimes registered by activation', function () {
    $singleton = new Container();
    $singleton->onMissing(function (string $id, Container $container): void {
        $container->singleton($id, MissingResolutionService::class);
    });

    $scoped = new Container();
    $scoped->onMissing(function (string $id, Container $container): void {
        $container->scoped($id, MissingResolutionService::class);
    });
    $scoped->enterScope('first');
    $first = $scoped->get(MissingResolutionContract::class);
    $firstAgain = $scoped->get(MissingResolutionContract::class);
    $scoped->leaveScope()->enterScope('second');
    $second = $scoped->get(MissingResolutionContract::class);

    expect($singleton->get(MissingResolutionContract::class))
        ->toBe($singleton->get(MissingResolutionContract::class))
        ->and($first)->toBe($firstAgain)
        ->and($second)->not->toBe($first);
});

it('works through generic and compiled resolvers', function () {
    $generic = new Container();
    $generic->options()->setOptions(injection: false);
    $generic->onMissing(function (string $id, Container $container): void {
        $container->singleton($id, MissingResolutionService::class);
    });

    $compiled = new Container();
    $compiled->bind(
        'compiled.root',
        FactoryDefinition::construct(MissingResolutionCompiledProduct::class, [
            new ServiceReference(MissingResolutionContract::class),
        ]),
    );
    $compiled->onMissing(function (string $id, Container $container): void {
        $container->singleton($id, MissingResolutionService::class);
    });
    $path = sys_get_temp_dir() . '/intermix-missing-' . uniqid('', true) . '.php';
    $compiled->compileTo($path, load: true);

    expect($generic->getCurrentResolver())->toBeInstanceOf(GenericCall::class)
        ->and($generic->get(MissingResolutionContract::class))->toBeInstanceOf(MissingResolutionService::class)
        ->and($compiled->getCurrentResolver())->toBeInstanceOf(CompiledCall::class)
        ->and($compiled->get('compiled.root')->service)->toBeInstanceOf(MissingResolutionService::class);
});

it('does not weaken container locking', function () {
    $container = new Container();
    $container->onMissing(function (string $id, Container $container): void {
        $container->value($id, 'late');
    });
    $container->lock();

    expect(fn() => $container->get('locked.missing'))
        ->toThrow(ContainerException::class, 'Container is locked')
        ->and(fn() => $container->onMissing(static function (): void {}))
        ->toThrow(ContainerException::class, 'Container is locked');
});
