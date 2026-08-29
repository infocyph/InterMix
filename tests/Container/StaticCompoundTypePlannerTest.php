<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

interface StaticCompoundLeft {}

interface StaticCompoundRight {}

final class StaticCompoundBoth implements StaticCompoundLeft, StaticCompoundRight {}

final class StaticCompoundFirst {}

final class StaticCompoundSecond {}

final class StaticCompoundFallback {}

final readonly class StaticCompoundUnionConsumer
{
    public function __construct(public StaticCompoundFirst|StaticCompoundSecond $dependency) {}
}

final readonly class StaticCompoundIntersectionConsumer
{
    public function __construct(public StaticCompoundLeft&StaticCompoundRight $dependency) {}
}

final readonly class StaticCompoundDnfConsumer
{
    public function __construct(public (StaticCompoundLeft&StaticCompoundRight)|StaticCompoundFallback $dependency) {}
}

final readonly class StaticCompoundNullableConsumer
{
    public function __construct(public (StaticCompoundLeft&StaticCompoundRight)|null $dependency) {}
}

function staticCompoundArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-compound-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticCompoundArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles ordered union autowiring with development parity', function () {
    $container = new Container(uniqid('static_compound_union_'));
    $container->singleton('consumer', StaticCompoundUnionConsumer::class);

    $development = $container->get('consumer');
    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $production = $generated['runtime']->get('consumer');

        expect($generated['compiled'])->toContain('consumer', StaticCompoundFirst::class)
            ->and($production)->toBeInstanceOf(StaticCompoundUnionConsumer::class)
            ->and($production->dependency::class)->toBe($development->dependency::class)
            ->and($production->dependency)->toBeInstanceOf(StaticCompoundFirst::class);
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('compiles intersection types through a parameter-name definition', function () {
    $container = new Container(uniqid('static_compound_intersection_'));
    $container->singleton('dependency', StaticCompoundBoth::class);
    $container->singleton('consumer', StaticCompoundIntersectionConsumer::class);

    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $consumer = $generated['runtime']->get('consumer');

        expect($generated['compiled'])->toContain('dependency', 'consumer')
            ->and($consumer->dependency)->toBeInstanceOf(StaticCompoundBoth::class)
            ->and($consumer->dependency)->toBeInstanceOf(StaticCompoundLeft::class)
            ->and($consumer->dependency)->toBeInstanceOf(StaticCompoundRight::class);
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('proves aliases targeting implicit classes for compound parameters', function () {
    $container = new Container(uniqid('static_compound_alias_'));
    $container->bind(
        'dependency',
        new AliasDefinition(StaticCompoundBoth::class),
        LifetimeEnum::Transient,
    );
    $container->singleton('consumer', StaticCompoundIntersectionConsumer::class);

    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $consumer = $generated['runtime']->get('consumer');

        expect($generated['compiled'])->toContain('dependency', StaticCompoundBoth::class, 'consumer')
            ->and($consumer->dependency)->toBeInstanceOf(StaticCompoundBoth::class);
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('compiles DNF types using the first statically resolvable group', function () {
    $container = new Container(uniqid('static_compound_dnf_'));
    $container->singleton('consumer', StaticCompoundDnfConsumer::class);

    $development = $container->get('consumer');
    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $production = $generated['runtime']->get('consumer');

        expect($production->dependency::class)->toBe($development->dependency::class)
            ->and($production->dependency)->toBeInstanceOf(StaticCompoundFallback::class)
            ->and($generated['compiled'])->toContain(StaticCompoundFallback::class, 'consumer');
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('folds environment bindings for interface intersections', function () {
    $container = new Container(uniqid('static_compound_environment_'));
    $container->singleton('consumer', StaticCompoundIntersectionConsumer::class);
    $container->options()->bindInterfaceForEnv(
        'production',
        StaticCompoundLeft::class,
        StaticCompoundBoth::class,
    );
    $container->setEnvironment('production');

    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $consumer = $generated['runtime']->get('consumer');

        expect($generated['compiled'])->toContain(StaticCompoundBoth::class, 'consumer')
            ->and($consumer->dependency)->toBeInstanceOf(StaticCompoundBoth::class);
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('keeps dynamic compound contextual bindings in the fallback island', function () {
    $container = new Container(uniqid('static_compound_dynamic_context_'));
    $container->singleton('consumer', StaticCompoundIntersectionConsumer::class);
    $container->when(StaticCompoundIntersectionConsumer::class)
        ->needs(StaticCompoundLeft::class)
        ->give(static fn(): StaticCompoundBoth => new StaticCompoundBoth());

    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );

        expect($generated['compiled'])->not->toContain('consumer')
            ->and($generated['skipped']['consumer'])->toContain('dynamic contextual binding');
    } finally {
        removeStaticCompoundArtifact($path);
    }
});

it('compiles unresolved nullable compound parameters as null', function () {
    $container = new Container(uniqid('static_compound_nullable_'));
    $container->singleton('consumer', StaticCompoundNullableConsumer::class);

    $path = staticCompoundArtifactPath();
    try {
        $generated = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($container->getRepository()),
            $path,
        );
        $consumer = $generated['runtime']->get('consumer');

        expect($generated['compiled'])->toContain('consumer')
            ->and($consumer->dependency)->toBeNull();
    } finally {
        removeStaticCompoundArtifact($path);
    }
});
