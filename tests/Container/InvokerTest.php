<?php

declare(strict_types=1);
/**
 * @covers \Infocyph\InterMix\DI\Invoker
 */

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\Exceptions\ContainerException;


/* -----------------------------------------------------------------
 |  Fixtures
 |-----------------------------------------------------------------*/
class InvokableFoo
{
    public function __invoke(string $msg = 'foo'): string
    {
        return strtoupper($msg);
    }
}

class MyService
{
    public function __construct(public string $id = '')
    {
    }

    public function run(string $p): string
    {
        return "$p processed";
    }
}

/* -----------------------------------------------------------------
 |  Shared test setup
 |-----------------------------------------------------------------*/
beforeEach(function () {
    $this->c  = Container::instance(uniqid('invoker_'));
    $this->c->definitions()
        ->bind(DateTimeZone::class, fn () => new DateTimeZone('UTC'));
    $this->inv = Invoker::with($this->c);
});


/* -----------------------------------------------------------------
 |  1. invoke() happy-paths
 |-----------------------------------------------------------------*/
it('invokes a native closure', function () {
    expect($this->inv->invoke(fn () => 'hi'))->toBe('hi');
});

it('invokes a function string', function () {
    expect($this->inv->invoke('strtoupper', ['abc']))->toBe('ABC');
});

it('invokes an invokable object', function () {
    expect($this->inv->invoke(new InvokableFoo(), ['bar']))->toBe('BAR');
});

it('invokes a [class,method] pair', function () {
    $out = $this->inv->invoke([MyService::class, 'run'], ['go']);
    expect($out)->toBe('go processed');
});

it('throws a ContainerException on unsupported target', function () {
    $this->inv->invoke('not-a-callable');
})->throws(ContainerException::class);

/* Opis-packed closure ------------------------------------------------------ */
it('executes a serialized-closure string', function () {
    $packed = $this->inv->serialize(fn () => 'packed');
    expect($this->inv->invoke($packed))->toBe('packed');
});


/* -----------------------------------------------------------------
 |  2. resolve()
 |-----------------------------------------------------------------*/
it('resolves a bound scalar service', function () {
    $this->c->definitions()->bind('foo', 99);
    expect($this->inv->resolve('foo'))->toBe(99);
});


/* -----------------------------------------------------------------
 |  3. make()
 |-----------------------------------------------------------------*/
it('make() returns fresh instances', function () {
    $a = $this->inv->make(MyService::class, ['A']);
    $b = $this->inv->make(MyService::class, ['B']);
    expect($a)->not->toBe($b)
        ->and($a->id)->toBe('A')
        ->and($b->id)->toBe('B');
});

it('make() can call a method', function () {
    $out = $this->inv->make(MyService::class, [], 'run', ['X']);
    expect($out)->toBe('X processed');
});


/* -----------------------------------------------------------------
 |  4. Serializer round-trip
 |-----------------------------------------------------------------*/
it('serialises and restores closures', function () {
    $packed   = $this->inv->serialize(fn () => 42);
    $restored = $this->inv->unserialize($packed);

    expect($restored)
        ->toBeInstanceOf(Closure::class)
        ->and($restored())->toBe(42);
});


/* -----------------------------------------------------------------
 |  5. NEW — pure callable + DI-injected parameters
 |-----------------------------------------------------------------*/

/* helper expectation */

expect()->extend('toStartWith', function (string $prefix) {
    /** @var \Pest\Expectation $this */
    return $this->and(substr($this->value, 0, strlen($prefix)) === $prefix);
});

it('invokes an anonymous closure with DI-resolved parameters', function () {

    // Enable autowiring so DateTimeImmutable can be injected
    $this->c->options()->setOptions(injection: true);

    $closure = function (DateTimeImmutable $now, string $name): string {
        return "Hi $name — " . $now->format('Y-m-d H:i');
    };

    $out = $this->inv->invoke($closure, ['Bob']);

    expect($out)->toStartWith('Hi Bob — ');
});

if (!function_exists('greet_time_test')) {
    function greet_time_test(DateTimeImmutable $now, string $name): string
    {
        return strtoupper("hello $name @ " . $now->format('H:i'));
    }
}

it('invokes a named function string with DI-resolved parameters', function () {

    $this->c->options()->setOptions(injection: true);

    $out = $this->inv->invoke('greet_time_test', ['Alice']);

    expect($out)->toMatch('/HELLO ALICE @ \d{2}:\d{2}/');
});

class Request
{
    // imagine PSR-7 or your own request here …
    public string $uuid;

    public function __construct()
    {
        $this->uuid = uniqid('req_', true);
    }
}

class CachedCallableService
{
    public function __construct(private readonly DateTimeZone $timezone) {}

    public function __invoke(): string
    {
        return $this->timezone->getName();
    }
}

/* 6. Invoker should autowire an argument-less class ----------------*/
it('invokes a closure and autowires a Request instance', function () {
    $out = $this->inv->invoke(
        fn (Request $request) => $request->uuid
    );

    // just assert we got **some** non-empty UUID back
    expect($out)->toBeString()->not->toBe('')->toStartWith('req_');
});

it('isolates callableFor cache per container', function () {
    $c1 = Container::instance(uniqid('invoker_cache_a_'));
    $c1->definitions()->bind(DateTimeZone::class, fn () => new DateTimeZone('UTC'));

    $c2 = Container::instance(uniqid('invoker_cache_b_'));
    $c2->definitions()->bind(DateTimeZone::class, fn () => new DateTimeZone('Asia/Dhaka'));

    $inv1 = Invoker::with($c1);
    $inv2 = Invoker::with($c2);

    $f1 = $inv1->callableFor(CachedCallableService::class);
    $f2 = $inv2->callableFor(CachedCallableService::class);

    expect($f1())->toBe('UTC')
        ->and($f2())->toBe('Asia/Dhaka');
});

it('releases cached invokable instances with their invoker lifecycle', function () {
    $container = Container::instance(uniqid('invoker_cache_lifecycle_'));
    $container->definitions()->bind(DateTimeZone::class, fn () => new DateTimeZone('UTC'));
    $invoker = Invoker::with($container);
    $callable = $invoker->callableFor(CachedCallableService::class);

    $reflection = new ReflectionFunction($callable);
    $service = $reflection->getClosureThis();
    expect($service)->toBeInstanceOf(CachedCallableService::class);
    $weakService = WeakReference::create($service);

    $container->unset();
    unset($reflection, $service, $callable, $invoker, $container);
    gc_collect_cycles();

    expect($weakService->get())->toBeNull();
});

it('invokes closures directly without storing closure aliases', function () {
    $before = count($this->c->getRepository()->getClosureResource());

    for ($i = 0; $i < 5; $i++) {
        expect($this->inv->invoke(fn () => 'ok'))->toBe('ok');
    }

    $after = count($this->c->getRepository()->getClosureResource());
    expect($after)->toBe($before);
});

it('resolves one-off closures without storing closure aliases', function () {
    $before = count($this->c->getRepository()->getClosureResource());

    for ($i = 0; $i < 5; $i++) {
        expect($this->c->resolveNow(fn () => 'ok'))->toBe('ok');
    }

    expect($this->c->getRepository()->getClosureResource())->toHaveCount($before);
});
