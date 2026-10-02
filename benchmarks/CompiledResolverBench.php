<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[AfterMethods('tearDown')]
#[Iterations(7)]
#[Warmup(1)]
final class CompiledResolverBench
{
    private string $artifact;

    private ProductionContainer $compiledRuntime;

    private int $containerCounter = 0;

    private Container $dynamicRuntime;

    private Container $hotRuntime;

    private mixed $sink;

    private string $validatedDigest;

    public function setUp(): void
    {
        $this->artifact = sys_get_temp_dir() . '/intermix-compiled-resolver-bench-' . getmypid() . '.php';
        $source = $this->builder('source', 100);
        $report = $source->compile($this->artifact);
        $this->validatedDigest = $report['digest'];

        $this->dynamicRuntime = $this->builder('dynamic-runtime', 1)->build();
        $this->dynamicRuntime->get('compiled.root.0');

        $compiledBuilder = $this->builder('compiled-runtime', 1);
        $runtimeArtifact = $this->artifact . '.runtime';
        $compiledBuilder->compile($runtimeArtifact);
        $this->compiledRuntime = $compiledBuilder->production($runtimeArtifact);
        $this->compiledRuntime->get('compiled.root.0');

        $this->hotRuntime = ContainerBuilder::create($this->alias('hot-runtime'))
            ->autowire(CompiledBenchLeaf::class, CompiledBenchLeaf::class)
            ->autowire('hot.singleton', CompiledBenchRoot::class)
            ->autowire('hot.scoped', CompiledBenchRoot::class, lifetime: LifetimeEnum::Scoped)
            ->build();
        $this->hotRuntime->get('hot.singleton');
    }

    public function tearDown(): void
    {
        foreach ([
            $this->artifact,
            $this->artifact . '.meta.json',
            $this->artifact . '.runtime',
            $this->artifact . '.runtime.meta.json',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Revs(5)]
    public function benchCacheGeneration(): void
    {
        $builder = $this->builder('cache', 100);
        $this->sink = $builder->compile($this->artifact);
    }

    #[Revs(100)]
    public function benchCompiledBoot(): void
    {
        $this->sink = $this->builder('compiled-boot', 100)->production($this->artifact);
    }

    #[Revs(100)]
    public function benchCompiledFirstResolution(): void
    {
        $runtime = $this->builder('compiled-first', 100)->production($this->artifact);
        $this->sink = $runtime->get('compiled.root.0');
    }

    #[Revs(1000)]
    public function benchCompiledTransientResolution(): void
    {
        $this->sink = $this->compiledRuntime->get('compiled.root.0');
    }

    #[Revs(100)]
    public function benchContainerConstruction(): void
    {
        $this->sink = ContainerBuilder::create($this->alias('construction'))->build();
    }

    #[Revs(100)]
    public function benchDynamicBoot(): void
    {
        $this->sink = $this->builder('dynamic-boot', 100)->build();
    }

    #[Revs(100)]
    public function benchDynamicFirstResolution(): void
    {
        $this->sink = $this->builder('dynamic-first', 100)->build()->get('compiled.root.0');
    }

    #[Revs(1000)]
    public function benchDynamicTransientResolution(): void
    {
        $this->sink = $this->dynamicRuntime->get('compiled.root.0');
    }

    #[Revs(1000)]
    public function benchHasBoundService(): void
    {
        $this->sink = $this->hotRuntime->has('hot.singleton');
    }

    #[Revs(100)]
    public function benchPrevalidatedCompiledBoot(): void
    {
        $this->sink = $this->builder('prevalidated-boot', 100)
            ->productionPrevalidated($this->artifact, $this->validatedDigest);
    }

    #[Revs(100)]
    public function benchPrevalidatedCompiledFirstResolution(): void
    {
        $runtime = $this->builder('prevalidated-first', 100)
            ->productionPrevalidated($this->artifact, $this->validatedDigest);
        $this->sink = $runtime->get('compiled.root.0');
    }

    #[Revs(1000)]
    public function benchScopedHotPath(): void
    {
        $this->sink = $this->hotRuntime->withinScope(
            'benchmark-' . (++$this->containerCounter),
            static fn(Container $active): object => $active->get('hot.scoped'),
        );
    }

    #[Revs(100)]
    public function benchScopeEnterLeave(): void
    {
        $this->sink = $this->hotRuntime->withinScope(
            'scope-' . (++$this->containerCounter),
            static fn(): null => null,
        );
    }

    #[Revs(1000)]
    public function benchSingletonHotPath(): void
    {
        $this->sink = $this->hotRuntime->get('hot.singleton');
    }

    private function alias(string $purpose): string
    {
        return '__compiled_bench_' . $purpose . '_' . (++$this->containerCounter);
    }

    private function builder(string $purpose, int $roots): ContainerBuilder
    {
        $builder = ContainerBuilder::create($this->alias($purpose))
            ->autowire(CompiledBenchLeaf::class, CompiledBenchLeaf::class);
        for ($index = 0; $index < $roots; ++$index) {
            $builder->autowire(
                "compiled.root.$index",
                CompiledBenchRoot::class,
                lifetime: LifetimeEnum::Transient,
            );
        }

        return $builder;
    }
}

final class CompiledBenchLeaf {}

final readonly class CompiledBenchRoot
{
    public function __construct(public CompiledBenchLeaf $leaf) {}
}
