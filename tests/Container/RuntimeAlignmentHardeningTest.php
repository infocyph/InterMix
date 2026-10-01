<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class RuntimeAlignmentCompiledLeaf {}

function runtimeAlignmentArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-runtime-alignment-' . bin2hex(random_bytes(8)) . '.php';
}

function removeRuntimeAlignmentArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps live Fiber carrier identities distinct', function () {
    $fibers = [];
    $ids = [];

    for ($i = 0; $i < 64; ++$i) {
        $fiber = new Fiber(static function (): void {
            Fiber::suspend(ExecutionContext::id());
        });
        $ids[] = $fiber->start();
        $fibers[] = $fiber;
    }

    expect(array_filter($ids, is_string(...)))->toHaveCount(64)
        ->and(array_unique($ids))->toHaveCount(64);

    foreach ($fibers as $fiber) {
        $fiber->resume();
    }
});

it('preserves compiled and fallback scoped identity after builder finalization', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_fallback_'));
    $builder->autowire('compiled', RuntimeAlignmentCompiledLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->factory('dynamic', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    $path = runtimeAlignmentArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $compiled = $runtime->get('compiled');
        $dynamic = $runtime->get('dynamic');
        $context = $runtime->captureScopeContext();

        expect(fn() => $builder->value('late.value', 'blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized');

        $fiber = new Fiber(static fn(): array => $runtime->withinScopeContext(
            $context,
            static fn($active): array => [
                $active->get('compiled'),
                $active->get('dynamic'),
            ],
        ));
        $fiber->start();
        [$childCompiled, $childDynamic] = $fiber->getReturn();

        expect($childCompiled)->toBe($compiled)
            ->and($childDynamic)->toBe($dynamic);

        $runtime->leaveScope();
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});

it('rejects dynamic configuration mutation from a foreign carrier while its scope is active', function () {
    $container = new ConfigurationContainer(uniqid('runtime_alignment_dynamic_mutation_'));
    $container->value('stable', 'baseline');

    $fiber = new Fiber(static function () use ($container): void {
        $container->enterScope('request');
        Fiber::suspend();
        $container->leaveScope();
    });
    $fiber->start();

    expect(fn() => $container->value('late', 'blocked'))
        ->toThrow(ContainerException::class, 'concurrent scope execution is active')
        ->and(fn() => $container->setEnvironment('blocked'))
        ->toThrow(ContainerException::class, 'concurrent scope execution is active')
        ->and($container->getRepository()->hasFunctionReference('late'))->toBeFalse();

    $fiber->resume();
    $container->value('late', 'allowed');

    expect($container->get('late'))->toBe('allowed');
});

it('keeps a finalized compiled graph immutable while a propagated child is attached', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_compiled_mutation_'));
    $builder->autowire('compiled', RuntimeAlignmentCompiledLeaf::class, lifetime: LifetimeEnum::Scoped);
    $path = runtimeAlignmentArtifactPath();
    try {
        $builder->compile($path);
        $report = $builder->compilationReport();
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $context = $runtime->captureScopeContext();

        $child = new Fiber(static fn(): RuntimeAlignmentCompiledLeaf => $runtime->withinScopeContext(
            $context,
            static function ($active): RuntimeAlignmentCompiledLeaf {
                $leaf = $active->get('compiled');
                Fiber::suspend();

                return $leaf;
            },
        ));
        $child->start();

        expect(fn() => $builder->value('late.value', 'blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($builder->compilationReport())->toBe($report)
            ->and($builder->build()->getRepository()->hasFunctionReference('late.value'))->toBeFalse();

        $child->resume();

        expect(fn() => $builder->value('late.value', 'still-blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($runtime->get('compiled'))->toBeInstanceOf(RuntimeAlignmentCompiledLeaf::class);

        $runtime->leaveScope();
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});
