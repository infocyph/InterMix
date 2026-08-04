<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Container;
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

    private Container $compiledRuntime;

    private int $containerCounter = 0;

    private Container $dynamicRuntime;

    private Container $hotRuntime;

    private mixed $sink;

    private string $validatedFingerprint;

    public function setUp(): void
    {
        $this->artifact = sys_get_temp_dir() . '/intermix-compiled-resolver-bench-' . getmypid() . '.php';
        $source = $this->container('source', 100);
        $source->compileTo($this->artifact);
        $this->validatedFingerprint = (string) $source->compilationReport()['fingerprint'];
        $source->unset();

        $this->dynamicRuntime = $this->container('dynamic-runtime', 1);
        $this->dynamicRuntime->get('compiled.root.0');

        $this->compiledRuntime = $this->container('compiled-runtime', 1);
        $runtimeArtifact = $this->artifact . '.runtime';
        $this->compiledRuntime->compileTo($runtimeArtifact, load: true);
        $this->compiledRuntime->get('compiled.root.0');

        $this->hotRuntime = $this->container('hot-runtime', 1);
        $this->hotRuntime->bind('hot.singleton', CompiledBenchRoot::class);
        $this->hotRuntime->bind('hot.scoped', CompiledBenchRoot::class, LifetimeEnum::Scoped);
        $this->hotRuntime->get('hot.singleton');
        $this->hotRuntime->enterScope('benchmark');
        $this->hotRuntime->get('hot.scoped');
    }

    public function tearDown(): void
    {
        $this->dynamicRuntime->unset();
        $this->compiledRuntime->unset();
        $this->hotRuntime->leaveScope();
        $this->hotRuntime->unset();
        foreach ([$this->artifact, $this->artifact . '.runtime'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Revs(5)]
    public function benchCacheGeneration(): void
    {
        $container = $this->container('cache', 100);
        $container->compileTo($this->artifact);
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(100)]
    public function benchCompiledBoot(): void
    {
        $container = $this->container('compiled-boot', 100);
        $container->useCompiled($this->artifact);
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(100)]
    public function benchCompiledFirstResolution(): void
    {
        $container = $this->container('compiled-first', 100);
        $container->useCompiled($this->artifact);
        $this->sink = $container->get('compiled.root.0');
        $container->unset();
    }

    #[Revs(1000)]
    public function benchCompiledTransientResolution(): void
    {
        $this->sink = $this->compiledRuntime->get('compiled.root.0');
    }

    #[Revs(100)]
    public function benchContainerConstruction(): void
    {
        $container = new Container('__compiled_bench_construction_' . (++$this->containerCounter));
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(100)]
    public function benchDynamicBoot(): void
    {
        $container = $this->container('dynamic-boot', 100);
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(100)]
    public function benchDynamicFirstResolution(): void
    {
        $container = $this->container('dynamic-first', 100);
        $this->sink = $container->get('compiled.root.0');
        $container->unset();
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
        $container = $this->container('prevalidated-boot', 100);
        $container->usePrevalidated($this->artifact, $this->validatedFingerprint);
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(100)]
    public function benchPrevalidatedCompiledFirstResolution(): void
    {
        $container = $this->container('prevalidated-first', 100);
        $container->usePrevalidated($this->artifact, $this->validatedFingerprint);
        $this->sink = $container->get('compiled.root.0');
        $container->unset();
    }

    #[Revs(1000)]
    public function benchScopedHotPath(): void
    {
        $this->sink = $this->hotRuntime->get('hot.scoped');
    }

    #[Revs(100)]
    public function benchScopeEnterLeave(): void
    {
        $scope = 'scope-' . (++$this->containerCounter);
        $this->hotRuntime->enterScope($scope);
        $this->hotRuntime->leaveScope();
    }

    #[Revs(1000)]
    public function benchSingletonHotPath(): void
    {
        $this->sink = $this->hotRuntime->get('hot.singleton');
    }

    private function container(string $purpose, int $roots): Container
    {
        $container = new Container(
            '__compiled_bench_' . $purpose . '_' . (++$this->containerCounter),
        );
        $container->bind(CompiledBenchLeaf::class, CompiledBenchLeaf::class);
        for ($index = 0; $index < $roots; ++$index) {
            $container->bind(
                "compiled.root.$index",
                CompiledBenchRoot::class,
                LifetimeEnum::Transient,
            );
        }

        return $container;
    }
}

final class CompiledBenchLeaf {}

final readonly class CompiledBenchRoot
{
    public function __construct(public CompiledBenchLeaf $leaf) {}
}
