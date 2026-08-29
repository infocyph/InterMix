<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Container\ContainerInterface;

#[Iterations(5)]
#[Warmup(1)]
final class CompiledRuntimeBench
{
    private int $sequence = 0;

    private mixed $sink;

    #[Revs(1000)]
    public function benchCompiledTransientGraph(): void
    {
        static $container;
        $container ??= $this->compiledTransientContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchCompiledWarmSingleton(): void
    {
        static $container;
        $container ??= $this->compiledSingletonContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchDynamicTransientGraph(): void
    {
        static $container;
        $container ??= $this->dynamicTransientContainer();
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
    public function benchNativeTransientGraph(): void
    {
        $this->sink = new CompiledBenchRoot(
            new CompiledBenchMiddle(new CompiledBenchLeaf()),
        );
    }

    #[Revs(1000)]
    public function benchStaticTransientGraph(): void
    {
        static $container;
        $container ??= $this->staticTransientContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchStaticWarmSingleton(): void
    {
        static $container;
        $container ??= $this->staticSingletonContainer();
        $this->sink = $container->get('root');
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
        $container = $this->registeredGraph('compiled-singleton', LifetimeEnum::Singleton);
        $path = $this->compiledPath();
        $container->compileTo($path, load: true);
        $container->get('root');
        $this->removeCompiledPath($path);

        return $container;
    }

    private function compiledTransientContainer(): Container
    {
        $container = $this->registeredGraph('compiled-transient', LifetimeEnum::Transient);
        $path = $this->compiledPath();
        $container->compileTo($path, load: true);
        $this->removeCompiledPath($path);

        return $container;
    }

    private function dynamicSingletonContainer(): Container
    {
        $container = $this->registeredGraph('dynamic-singleton', LifetimeEnum::Singleton);
        $container->get('root');

        return $container;
    }

    private function dynamicTransientContainer(): Container
    {
        return $this->registeredGraph('dynamic-transient', LifetimeEnum::Transient);
    }

    private function registeredGraph(string $purpose, LifetimeEnum $lifetime): Container
    {
        $container = new Container($this->alias($purpose));
        $container->bind(CompiledBenchLeaf::class, CompiledBenchLeaf::class, $lifetime);
        $container->bind(CompiledBenchMiddle::class, CompiledBenchMiddle::class, $lifetime);
        $container->bind('root', CompiledBenchRoot::class, $lifetime);

        return $container;
    }

    private function removeCompiledPath(string $path): void
    {
        foreach ([$path, $path . '.meta.json'] as $artifact) {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
    }

    private function staticRuntime(string $purpose, LifetimeEnum $lifetime): ContainerInterface
    {
        $source = $this->registeredGraph($purpose, $lifetime);
        $path = $this->compiledPath();
        $runtime = new StaticRuntimeGenerator()->generate(
            DefinitionGraph::from($source->getRepository()),
            $path,
        )['runtime'];
        $this->removeCompiledPath($path);

        return $runtime;
    }

    private function staticSingletonContainer(): ContainerInterface
    {
        return $this->staticRuntime('static-singleton', LifetimeEnum::Singleton);
    }

    private function staticTransientContainer(): ContainerInterface
    {
        return $this->staticRuntime('static-transient', LifetimeEnum::Transient);
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
