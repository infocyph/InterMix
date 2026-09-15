<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
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

it('does not reuse collected Fiber carrier identities', function () {
    $ids = [];

    for ($i = 0; $i < 64; ++$i) {
        $fiber = new Fiber(static fn(): ?string => ExecutionContext::id());
        $fiber->start();
        $ids[] = $fiber->getReturn();
        unset($fiber);
        gc_collect_cycles();
    }

    expect(array_filter($ids, is_string(...)))->toHaveCount(64)
        ->and(array_unique($ids))->toHaveCount(64);
});

it('preserves compiled and fallback scoped identity across capture and safe deoptimization', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_fallback_'));
    $builder->scoped('compiled', RuntimeAlignmentCompiledLeaf::class)
        ->bindFactory('dynamic', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    $path = runtimeAlignmentArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $compiled = $runtime->get('compiled');
        $dynamic = $runtime->get('dynamic');
        $context = $runtime->captureScopeContext();

        $builder->value('late.value', 'available-after-deopt');

        $fiber = new Fiber(static fn(): array => $runtime->withinScopeContext(
            $context,
            static fn($active): array => [
                $active->get('compiled'),
                $active->get('dynamic'),
                $active->get('late.value'),
            ],
        ));
        $fiber->start();
        [$childCompiled, $childDynamic, $late] = $fiber->getReturn();

        expect($childCompiled)->toBe($compiled)
            ->and($childDynamic)->toBe($dynamic)
            ->and($late)->toBe('available-after-deopt');

        $runtime->leaveScope();
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});

it('rejects dynamic configuration mutation from a foreign carrier while its scope is active', function () {
    $container = new Container(uniqid('runtime_alignment_dynamic_mutation_'));
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

it('rejects compiled graph mutation while a propagated child carrier is attached', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_compiled_mutation_'));
    $builder->scoped('compiled', RuntimeAlignmentCompiledLeaf::class);
    $development = $builder->development();

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

        expect(fn() => $runtime->deoptimize())
            ->toThrow(ContainerException::class, 'concurrent scope execution is active')
            ->and(fn() => $runtime->attachFallback(new Container(uniqid('unsafe_fallback_'))))
            ->toThrow(ContainerException::class, 'concurrent scope execution is active')
            ->and(fn() => $builder->value('late.value', 'blocked'))
            ->toThrow(ContainerException::class, 'concurrent scope execution is active')
            ->and($builder->compilationReport())->toBe($report)
            ->and($development->getRepository()->hasFunctionReference('late.value'))->toBeFalse();

        $child->resume();
        $builder->value('late.value', 'allowed');

        expect($runtime->get('late.value'))->toBe('allowed');
        $runtime->leaveScope();
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});
