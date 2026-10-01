<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class ArrayInvocationDependency {}

final class ArrayInvocationHandler
{
    public static int $calls = 0;

    public string $prefix = 'unset';

    public function __construct(public ArrayInvocationDependency $constructorDependency) {}

    public function handle(ArrayInvocationDependency $methodDependency, string $suffix = 'default'): string
    {
        ++self::$calls;

        return $this->prefix
            . ':' . $suffix
            . ':' . ($methodDependency === $this->constructorDependency ? 'same' : 'different');
    }

    public function registeredOnly(): void {}
}

final class ArrayInvocationNullHandler
{
    public static int $calls = 0;

    public function handle(): void
    {
        ++self::$calls;
    }
}

final class ArrayInvocationScopedHandler
{
    public static int $calls = 0;

    public function handle(): object
    {
        ++self::$calls;

        return new stdClass();
    }
}

final class ArrayInvocationObjectHandler
{
    public static int $calls = 0;

    public function __invoke(): void
    {
        ++self::$calls;
    }
}

function arrayInvocationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-array-invocation-' . bin2hex(random_bytes(8)) . '.php';
}

function removeArrayInvocationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles class-array definitions with constructor property and method recipes', function () {
    ArrayInvocationHandler::$calls = 0;
    $builder = ContainerBuilder::create(uniqid('array_invocation_'));
    $builder->autowire(ArrayInvocationDependency::class, ArrayInvocationDependency::class)
        ->factory('handler', static function (Container $runtime): string {
            $handler = new ArrayInvocationHandler($runtime->get(ArrayInvocationDependency::class));
            $handler->prefix = 'compiled';

            return $handler->handle($runtime->get(ArrayInvocationDependency::class), 'registered');
        });

    $path = arrayInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->not->toContain('handler')
            ->and($runtime->get('handler'))->toBe('compiled:registered:same')
            ->and($runtime->get('handler'))->toBe('compiled:registered:same')
            ->and(ArrayInvocationHandler::$calls)->toBe(1);
    } finally {
        removeArrayInvocationArtifact($path);
    }
});

it('caches null class-array invocation results for singleton definitions', function () {
    ArrayInvocationNullHandler::$calls = 0;
    $builder = ContainerBuilder::create(uniqid('array_invocation_null_'));
    $builder->factory('nullable.handler', static function (): mixed {
        return (new ArrayInvocationNullHandler())->handle();
    });

    $path = arrayInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->not->toContain('nullable.handler')
            ->and($runtime->get('nullable.handler'))->toBeNull()
            ->and($runtime->get('nullable.handler'))->toBeNull()
            ->and(ArrayInvocationNullHandler::$calls)->toBe(1);
    } finally {
        removeArrayInvocationArtifact($path);
    }
});

it('specializes scoped class-array invocation result caching', function () {
    ArrayInvocationScopedHandler::$calls = 0;
    $builder = ContainerBuilder::create(uniqid('array_invocation_scope_'));
    $builder->factory(
        'scoped.handler',
        static fn(): object => (new ArrayInvocationScopedHandler())->handle(),
        LifetimeEnum::Scoped,
    );

    $path = arrayInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        testEnterScope($runtime, 'request-a');
        $first = $runtime->get('scoped.handler');
        $second = $runtime->get('scoped.handler');
        testLeaveScope($runtime);

        testEnterScope($runtime, 'request-b');
        $third = $runtime->get('scoped.handler');
        testLeaveScope($runtime);

        expect($report['compiled'])->not->toContain('scoped.handler')
            ->and($first)->toBe($second)
            ->and($third)->not->toBe($first)
            ->and(ArrayInvocationScopedHandler::$calls)->toBe(2);
    } finally {
        removeArrayInvocationArtifact($path);
    }
});

it('compiles class-only arrays while preserving implicit method side effects', function () {
    ArrayInvocationObjectHandler::$calls = 0;
    $builder = ContainerBuilder::create(uniqid('array_invocation_object_'));
    $builder->autowire('object.handler', ArrayInvocationObjectHandler::class);

    $path = arrayInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $instance = $runtime->get('object.handler');

        expect($report['compiled'])->toContain('object.handler')
            ->and($instance)->toBeInstanceOf(ArrayInvocationObjectHandler::class)
            ->and($runtime->get('object.handler'))->toBe($instance)
            ->and(ArrayInvocationObjectHandler::$calls)->toBe(0);
        $runtime->invoke($instance);
        expect(ArrayInvocationObjectHandler::$calls)->toBe(1);
    } finally {
        removeArrayInvocationArtifact($path);
    }
});
