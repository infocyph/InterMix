<?php

/**  @covers \Infocyph\InterMix\DI\Invoker */

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\Exceptions\ContainerException;

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

/* -----------------------------------------------------------------*/
beforeEach(function () {
    $this->c = Container::instance(uniqid('invoker_'));
    $this->inv = Invoker::with($this->c);
});

/* 1. invoke() paths ---------------------------------------------- */
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
    $this->inv->invoke(123);
})->throws(ContainerException::class);

/* Opis packed closure -------------------------------------------- */
it('executes a serialized-closure string', function () {
    $packed = $this->inv->serialize(fn () => 'packed');
    expect($this->inv->invoke($packed))->toBe('packed');
});

/* 2. resolve() ---------------------------------------------------- */
it('resolves a bound scalar service', function () {
    $this->c->definitions()->bind('foo', 99);
    expect($this->inv->resolve('foo'))->toBe(99);
});

/* 3. make() ------------------------------------------------------- */
it('make() returns fresh instances', function () {
    $a = $this->inv->make(MyService::class, ['A']);
    $b = $this->inv->make(MyService::class, ['B']);
    expect($a)->not->toBe($b)->and($a->id)->toBe('A')->and($b->id)->toBe('B');
});

it('make() can call a method', function () {
    $out = $this->inv->make(MyService::class, [], 'run', ['X']);
    expect($out)->toBe('X processed');
});

/* 4. Serializer round-trip --------------------------------------- */
it('serialises and restores closures', function () {
    $packed = $this->inv->serialize(fn () => 42);
    $restored = $this->inv->unserialize($packed);
    expect($restored)
        ->toBeInstanceOf(Closure::class)
        ->and($restored())->toBe(42);
});
