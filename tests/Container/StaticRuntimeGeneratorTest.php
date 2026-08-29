<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Psr\Container\NotFoundExceptionInterface;

final class StaticRuntimeLeaf {}

final readonly class StaticRuntimeMiddle
{
    public function __construct(public StaticRuntimeLeaf $leaf) {}
}

final readonly class StaticRuntimeRoot
{
    public function __construct(public StaticRuntimeMiddle $middle) {}
}

function staticRuntimeArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-runtime-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticRuntimeArtifact(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

it('generates direct transient service graphs without a runtime repository', function () {
    $container = new Container(uniqid('static_runtime_transient_'));
    $container->bind(StaticRuntimeLeaf::class, StaticRuntimeLeaf::class, LifetimeEnum::Transient);
    $container->bind(StaticRuntimeMiddle::class, StaticRuntimeMiddle::class, LifetimeEnum::Transient);
    $container->bind('root', StaticRuntimeRoot::class, LifetimeEnum::Transient);

    $path = staticRuntimeArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $runtime = $generated['runtime'];

        $first = $runtime->get('root');
        $second = $runtime->get('root');

        expect($generated['compiled'])->toContain(
            StaticRuntimeLeaf::class,
            StaticRuntimeMiddle::class,
            'root',
        )
            ->and($first)->toBeInstanceOf(StaticRuntimeRoot::class)
            ->and($first->middle)->toBeInstanceOf(StaticRuntimeMiddle::class)
            ->and($first->middle->leaf)->toBeInstanceOf(StaticRuntimeLeaf::class)
            ->and($second)->not->toBe($first)
            ->and($second->middle)->not->toBe($first->middle)
            ->and($runtime->has('root'))->toBeTrue()
            ->and($runtime->has('missing'))->toBeFalse();
    } finally {
        removeStaticRuntimeArtifact($path);
    }
});

it('specializes singleton identity inside the generated runtime', function () {
    $container = new Container(uniqid('static_runtime_singleton_'));
    $container->singleton(StaticRuntimeLeaf::class);
    $container->singleton(StaticRuntimeMiddle::class);
    $container->singleton('root', StaticRuntimeRoot::class);

    $path = staticRuntimeArtifactPath();
    try {
        $runtime = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        )['runtime'];

        expect($runtime->get('root'))->toBe($runtime->get('root'))
            ->and($runtime->get(StaticRuntimeMiddle::class))->toBe($runtime->get(StaticRuntimeMiddle::class))
            ->and($runtime->get(StaticRuntimeLeaf::class))->toBe($runtime->get(StaticRuntimeLeaf::class));
    } finally {
        removeStaticRuntimeArtifact($path);
    }
});

it('rejects unknown identifiers using the PSR not-found contract', function () {
    expect(NotFoundException::class)->toImplement(NotFoundExceptionInterface::class);

    $container = new Container(uniqid('static_runtime_not_found_'));
    $container->singleton(StaticRuntimeLeaf::class);

    $path = staticRuntimeArtifactPath();
    try {
        $runtime = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        )['runtime'];

        expect(fn() => $runtime->get('missing'))->toThrow(NotFoundException::class);
    } finally {
        removeStaticRuntimeArtifact($path);
    }
});

it('does not specialize contextual graphs until contextual folding exists', function () {
    $container = new Container(uniqid('static_runtime_contextual_'));
    $container->singleton(StaticRuntimeLeaf::class);
    $container->singleton(StaticRuntimeMiddle::class);
    $container->singleton('root', StaticRuntimeRoot::class);
    $container->when(StaticRuntimeMiddle::class)
        ->needs(StaticRuntimeLeaf::class)
        ->give(new StaticRuntimeLeaf());

    $path = staticRuntimeArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );

        expect($generated['compiled'])->not->toContain(StaticRuntimeMiddle::class, 'root')
            ->and($generated['skipped'][StaticRuntimeMiddle::class])->toContain('contextual binding')
            ->and($generated['skipped']['root'])->toContain('not statically compiled');
    } finally {
        removeStaticRuntimeArtifact($path);
    }
});
