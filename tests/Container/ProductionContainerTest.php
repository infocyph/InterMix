<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;

final class ProductionRuntimeLeaf {}

final readonly class ProductionRuntimeMiddle
{
    public function __construct(public ProductionRuntimeLeaf $leaf) {}
}

final readonly class ProductionRuntimeRoot
{
    public function __construct(public ProductionRuntimeMiddle $middle) {}
}

function productionRuntimeArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-production-runtime-' . bin2hex(random_bytes(8)) . '.php';
}

function removeProductionRuntimeArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('separates build configuration from the generated production runtime', function () {
    $builder = ContainerBuilder::create(uniqid('production_builder_'))
        ->singleton('root', ProductionRuntimeRoot::class)
        ->value('app.name', 'InterMix');

    $path = productionRuntimeArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($runtime)->toBeInstanceOf(ProductionContainer::class)
            ->and($runtime->get('root'))->toBe($runtime->get('root'))
            ->and($runtime->get('root')->middle->leaf)->toBeInstanceOf(ProductionRuntimeLeaf::class)
            ->and($runtime->get('app.name'))->toBe('InterMix')
            ->and($report['compiled'])->toContain(
                'root',
                'app.name',
                ProductionRuntimeMiddle::class,
                ProductionRuntimeLeaf::class,
            );
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('specializes scoped identity and scope seeds in production', function () {
    $builder = ContainerBuilder::create(uniqid('production_scope_'))
        ->scoped('leaf', ProductionRuntimeLeaf::class);

    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $root = $runtime->get('leaf');

        $runtime->enterScope('request-a');
        $requestA = $runtime->get('leaf');
        expect($requestA)->toBe($runtime->get('leaf'))->not->toBe($root);
        $runtime->leaveScope();

        $seed = new ProductionRuntimeLeaf();
        $runtime->enterScope('request-b', ['leaf' => $seed]);
        expect($runtime->get('leaf'))->toBe($seed);
        $runtime->leaveScope();

        expect($runtime->get('leaf'))->toBe($root);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('keeps dynamic definitions and arbitrary classes as cold fallback islands', function () {
    $builder = ContainerBuilder::create(uniqid('production_dynamic_'))
        ->singleton('root', ProductionRuntimeRoot::class)
        ->bind('dynamic', static fn(): object => new stdClass());

    $path = productionRuntimeArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain('root')
            ->and($report['skipped'])->toHaveKey('dynamic')
            ->and($runtime->get('dynamic'))->toBe($runtime->get('dynamic'))
            ->and($runtime->get(ProductionRuntimeLeaf::class))->toBeInstanceOf(ProductionRuntimeLeaf::class);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('compiles tag indexes for known production services', function () {
    $builder = ContainerBuilder::create(uniqid('production_tags_'))
        ->singleton('first', ProductionRuntimeLeaf::class, ['worker'])
        ->transient('second', ProductionRuntimeLeaf::class, ['worker']);

    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        expect(array_keys($runtime->findByTag('worker')))->toBe(['first', 'second']);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('deoptimizes a prior production runtime before attaching another to the builder graph', function () {
    $builder = ContainerBuilder::create(uniqid('production_reload_'))
        ->singleton('leaf', ProductionRuntimeLeaf::class);
    $path = productionRuntimeArtifactPath();

    try {
        $report = $builder->compile($path);
        $first = $builder->production($path);
        $second = $builder->productionPrevalidated($path, $report['sha256']);
        $development = $builder->development();

        expect($first)->not->toBe($second)
            ->and($development->getRepository()->getFunctionDefinition('leaf'))
            ->toBe(ProductionRuntimeLeaf::class);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('deoptimizes when a retained development manager mutates the finalized graph', function () {
    $builder = ContainerBuilder::create(uniqid('production_retained_manager_'));
    $definitions = $builder->definitions();
    $definitions->bind('leaf', ProductionRuntimeLeaf::class);
    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $compiled = $runtime->get('leaf');
        $replacement = new ProductionRuntimeLeaf();

        $definitions->bind('leaf', $replacement);

        expect($runtime->get('leaf'))->toBe($replacement)
            ->and($runtime->get('leaf'))->not->toBe($compiled)
            ->and(fn() => $builder->production($path))
            ->toThrow(\Infocyph\InterMix\Exceptions\ContainerException::class, 'recompiled');
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});
