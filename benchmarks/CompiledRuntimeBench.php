<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Iterations(5)]
#[Warmup(1)]
final class CompiledRuntimeBench
{
    private int $sequence = 0;

    private mixed $sink;

    #[Revs(1000)]
    public function benchNativeTransientGraph(): void
    {
        $this->sink = new CompiledBenchRoot(
            new CompiledBenchMiddle(new CompiledBenchLeaf()),
        );
    }

    #[Revs(1000)]
    public function benchDynamicTransientGraph(): void
    {
        static $container;
        $container ??= $this->dynamicTransientContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchCompiledTransientGraph(): void
    {
        static $container;
        $container ??= $this->compiledTransientContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchDynamicWarmSingleton(): void
    {
        static $container;
        $container ??= $this->dynamicSingletonContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchCompiledWarmSingleton(): void
    {
        static $container;
        $container ??= $this->compiledSingletonContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(100)]
    public function benchCompiledContainerBootstrap(): void
    {
        $path = $this->compiledPath();
        $source = new Container($this->alias('bootstrap-source'));
        $source->transient('root', CompiledBenchRoot::class);
        $source->compileTo($path);
        $fingerprint = (string) $source->compilationReport()['fingerprint'];

        $runtime = new Container($this->alias('bootstrap-runtime'));
        $runtime->transient('root', CompiledBenchRoot::class);
        $runtime->usePrevalidated($path, $fingerprint);
        $this->sink = $runtime;

        $this->removeCompiledPath($path);
    }

    private function alias(string $purpose): string
    {
        return '__compiled_runtime_' . $purpose . '_' . (++$this->sequence);
    }

    private function compiledPath(): string
    {
        return sys_get_temp_dir() . '/intermix-bench-' . bin2hex(random_bytes(8)) . '.php';
    }

    private function compiledSingletonContainer(): Container
    {
        $container = new Container($this->alias('compiled-singleton'));
        $container->singleton('root', CompiledBenchRoot::class);
        $path = $this->compiledPath();
        $container->compileTo($path, load: true);
        $container->get('root');
        $this->removeCompiledPath($path);

        return $container;
    }

    private function compiledTransientContainer(): Container
    {
        $container = new Container($this->alias('compiled-transient'));
        $container->transient('root', CompiledBenchRoot::class);
        $path = $this->compiledPath();
        $container->compileTo($path, load: true);
        $this->removeCompiledPath($path);

        return $container;
    }

    private function dynamicSingletonContainer(): Container
    {
        $container = new Container($this->alias('dynamic-singleton'));
        $container->singleton('root', CompiledBenchRoot::class);
        $container->get('root');

        return $container;
    }

    private function dynamicTransientContainer(): Container
    {
        $container = new Container($this->alias('dynamic-transient'));
        $container->transient('root', CompiledBenchRoot::class);

        return $container;
    }

    private function removeCompiledPath(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

final class CompiledBenchLeaf {}

final readonly class CompiledBenchMiddle
{
    public function __construct(public CompiledBenchLeaf $leaf) {}
}

final readonly class CompiledBenchRoot
{
    public function __construct(public CompiledBenchMiddle $middle) {}
}
