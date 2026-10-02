<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Serializer\ClosureSerializer;

final class RuntimeInvokeTarget
{
    public function __construct(public string $id = '') {}

    public function run(string $value): string
    {
        return $value . ' processed';
    }
}

final class RuntimeInvokableTarget
{
    public int $calls = 0;

    public function __invoke(string $value): string
    {
        ++$this->calls;

        return strtoupper($value);
    }
}

final class RuntimeStaticTarget
{
    public static function timezone(DateTimeZone $timezone): string
    {
        return $timezone->getName();
    }

    public static function fresh(): stdClass
    {
        return new stdClass();
    }
}

function runtimeInvokeContainer(): \Infocyph\InterMix\DI\RuntimeContainerInterface
{
    return ContainerBuilder::create()
        ->value(DateTimeZone::class, new DateTimeZone('UTC'))
        ->build();
}

test('invoke supports native closure, function, invokable object, instance method, and static method callables', function () {
    $runtime = runtimeInvokeContainer();
    $target = new RuntimeInvokeTarget();
    $invokable = new RuntimeInvokableTarget();

    expect($runtime->invoke(static fn(): string => 'ok'))->toBe('ok')
        ->and($runtime->invoke('strtoupper', ['abc']))->toBe('ABC')
        ->and($runtime->invoke($invokable, ['value' => 'hello']))->toBe('HELLO')
        ->and($runtime->invoke([$target, 'run'], ['value' => 'work']))->toBe('work processed')
        ->and($runtime->invoke([RuntimeStaticTarget::class, 'timezone']))->toBe('UTC');
});

test('invoke preserves exact values and never caches results', function () {
    $runtime = runtimeInvokeContainer();

    expect($runtime->invoke(static fn(): null => null))->toBeNull()
        ->and($runtime->invoke(static fn(): false => false))->toBeFalse()
        ->and($runtime->invoke([RuntimeStaticTarget::class, 'fresh']))
        ->not->toBe($runtime->invoke([RuntimeStaticTarget::class, 'fresh']));
});

test('instance methods require an explicit receiver', function () {
    $runtime = runtimeInvokeContainer();

    expect(fn() => $runtime->invoke([RuntimeInvokeTarget::class, 'run'], ['value' => 'x']))
        ->toThrow(TypeError::class);
});

test('serialized closures require explicit bounded deserialization', function () {
    $runtime = runtimeInvokeContainer();
    $payload = ClosureSerializer::serialize(static fn(): string => 'packed');

    expect(fn() => $runtime->invoke($payload))->toThrow(TypeError::class)
        ->and($runtime->invoke(ClosureSerializer::unserialize($payload)))->toBe('packed');
});

test('make constructs fresh roots with named and positional arguments', function () {
    $runtime = runtimeInvokeContainer();
    $named = $runtime->make(RuntimeInvokeTarget::class, ['id' => 'named']);
    $positional = $runtime->make(RuntimeInvokeTarget::class, ['positional']);

    expect($named)->not->toBe($positional)
        ->and($named->id)->toBe('named')
        ->and($positional->id)->toBe('positional');
});

test('make constructs invokable classes without invoking them', function () {
    $runtime = runtimeInvokeContainer();
    $target = $runtime->make(RuntimeInvokableTarget::class);

    expect($target)->toBeInstanceOf(RuntimeInvokableTarget::class)
        ->and($target->calls)->toBe(0);
});

test('invoke autowires missing concrete parameters and honors explicit arguments', function () {
    $runtime = runtimeInvokeContainer();

    $value = $runtime->invoke(
        static fn(DateTimeImmutable $now, string $name): string => $name . ':' . $now->format('Y'),
        ['name' => 'Ada'],
    );

    expect($value)->toMatch('/^Ada:\\d{4}$/');
});

test('invoke does not retain caller-owned closures', function () {
    $runtime = runtimeInvokeContainer();
    $closure = static fn(): string => 'ok';
    $weak = WeakReference::create($closure);

    expect($runtime->invoke($closure))->toBe('ok');
    unset($closure);
    gc_collect_cycles();

    expect($weak->get())->toBeNull();
});

test('the retired Invoker facade is absent', function () {
    expect(class_exists('Infocyph\\InterMix\\DI\\Invoker'))->toBeFalse();
});
