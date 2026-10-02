<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

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
        ->releaseIdentity('intermix-test')
        ->autowire('root', ProductionRuntimeRoot::class)
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
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ProductionRuntimeLeaf::class, lifetime: LifetimeEnum::Scoped);

    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $source = file_get_contents($path);
        expect($source)->toBeString()
            ->toContain('$scope = $this->contextScopesActive ? $this->compiledScope() : $this->scope;')
            ->toContain('$scope->hasSeeds && array_key_exists(');

        $runtime = $builder->production($path);
        expect(fn() => $runtime->get('leaf'))->toThrow(ContainerException::class);

        $requestA = $runtime->withinScope('request-a', static function (ProductionContainer $active): object {
            $leaf = $active->get('leaf');
            expect($active->get('leaf'))->toBe($leaf);

            return $leaf;
        });

        $seed = new ProductionRuntimeLeaf();
        $requestB = $runtime->withinScope(
            'request-b',
            static fn(ProductionContainer $active): object => $active->get('leaf'),
            ['leaf' => $seed],
        );

        expect($requestB)->toBe($seed)
            ->and($requestA)->not->toBe($requestB)
            ->and(fn() => $runtime->get('leaf'))->toThrow(ContainerException::class);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('keeps dynamic definitions and arbitrary classes as cold fallback islands', function () {
    $builder = ContainerBuilder::create(uniqid('production_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('root', ProductionRuntimeRoot::class)
        ->factory('dynamic', static fn(): object => new stdClass());

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

it('compiles direct eager and lazy tag dispatch for known production services', function () {
    $builder = ContainerBuilder::create(uniqid('production_tags_'))
        ->releaseIdentity('intermix-test')
        ->autowire('first', ProductionRuntimeLeaf::class, tags: ['worker'])
        ->autowire('second', ProductionRuntimeLeaf::class, lifetime: LifetimeEnum::Transient, tags: ['worker']);

    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);
        $firstPass = iterator_to_array($runtime->tagged('worker'));
        $secondPass = iterator_to_array($runtime->tagged('worker'));

        expect($source)->toBeString()
            ->toContain('protected function compiledTagged(string $tag): ?array')
            ->and(array_keys($firstPass))->toBe(['first', 'second'])
            ->and(array_keys($secondPass))->toBe(['first', 'second'])
            ->and($secondPass['first'])->toBe($firstPass['first'])
            ->and($secondPass['second'])->toBeInstanceOf(ProductionRuntimeLeaf::class)
            ->and($secondPass['second'])->not->toBe($firstPass['second'])
            ->and(iterator_to_array($runtime->tagged('missing')))->toBe([]);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('loads independent production runtimes from the same frozen graph', function () {
    $builder = ContainerBuilder::create(uniqid('production_reload_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ProductionRuntimeLeaf::class);
    $path = productionRuntimeArtifactPath();

    try {
        $report = $builder->compile($path);
        $first = $builder->production($path);
        $second = $builder->productionPrevalidated($path, $report['digest']);

        expect($first)->not->toBe($second)
            ->and($first->get('leaf'))->toBeInstanceOf(ProductionRuntimeLeaf::class)
            ->and($second->get('leaf'))->toBeInstanceOf(ProductionRuntimeLeaf::class)
            ->and($first->get('leaf'))->not->toBe($second->get('leaf'));
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});

it('rejects builder mutation after finalization without changing built runtimes', function () {
    $builder = ContainerBuilder::create(uniqid('production_frozen_builder_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ProductionRuntimeLeaf::class);
    $path = productionRuntimeArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $compiled = $runtime->get('leaf');

        expect(fn() => $builder->value('late', true))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($runtime->get('leaf'))->toBe($compiled);

        $second = $builder->production($path);
        expect($second)->not->toBe($runtime)
            ->and($second->get('leaf'))->toBeInstanceOf(ProductionRuntimeLeaf::class);
    } finally {
        removeProductionRuntimeArtifact($path);
    }
});
