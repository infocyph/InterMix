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
final class RequestPathBench
{
    private int $sequence = 0;

    private mixed $sink;

    #[Revs(100)]
    public function benchColdContainerConstruction(): void
    {
        $container = new Container($this->alias('construct'));
        $container->unset();
        $this->sink = $container;
    }

    #[Revs(50)]
    public function benchColdRegister10(): void
    {
        $this->sink = $this->registeredContainer(10, 'register-10');
    }

    #[Revs(10)]
    public function benchColdRegister100(): void
    {
        $this->sink = $this->registeredContainer(100, 'register-100');
    }

    #[Revs(20)]
    public function benchColdRegister50(): void
    {
        $this->sink = $this->registeredContainer(50, 'register-50');
    }

    #[Revs(100)]
    public function benchFirstAutowiredGraph(): void
    {
        $container = new Container($this->alias('first-graph'));
        $this->sink = $container->get(RequestBenchRoot::class);
    }

    #[Revs(100)]
    public function benchFirstDiClosure(): void
    {
        $container = new Container($this->alias('first-closure'));
        $handler = static fn(RequestBenchRoot $root): int => $root->handle();
        $this->sink = $container->call($handler);
    }

    #[Revs(100)]
    public function benchFirstMethodInvocation(): void
    {
        $container = new Container($this->alias('first-method'));
        $this->sink = $container->call(RequestBenchController::class, 'handle');
    }

    #[Revs(100)]
    public function benchFirstScopedResolution(): void
    {
        $container = new Container($this->alias('first-scope'));
        $container->scoped('root', RequestBenchRoot::class);
        $container->enterScope('request');
        $this->sink = $container->get('root');
        $container->leaveScope();
    }

    #[Revs(100)]
    public function benchFirstSingletonResolution(): void
    {
        $container = new Container($this->alias('first-singleton'));
        $container->singleton('service', RequestBenchRoot::class);
        $this->sink = $container->get('service');
    }

    #[Revs(1000)]
    public function benchHotOneDependencyClosure(): void
    {
        static $container;
        static $handler;
        if (!$container instanceof Container) {
            $container = $this->hotContainer();
            $handler = static fn(RequestBenchLeaf $leaf): int => $leaf->value();
            $container->call($handler);
        }
        $this->sink = $container->call($handler);
    }

    #[Revs(1000)]
    public function benchHotScopedResolution(): void
    {
        static $container;
        if (!$container instanceof Container) {
            $container = new Container($this->alias('hot-scope'));
            $container->scoped('root', RequestBenchRoot::class);
            $container->enterScope('request');
            $container->get('root');
        }
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchHotSingletonResolution(): void
    {
        static $container;
        $container ??= $this->hotContainer();
        $this->sink = $container->get('root');
    }

    #[Revs(1000)]
    public function benchNativeGraph(): void
    {
        $this->sink = (new RequestBenchRoot(
            new RequestBenchMiddle(new RequestBenchLeaf()),
        ))->handle();
    }

    private function alias(string $purpose): string
    {
        return '__request_path_' . $purpose . '_' . (++$this->sequence);
    }

    private function hotContainer(): Container
    {
        $container = new Container($this->alias('hot'));
        $container->singleton('root', RequestBenchRoot::class);
        $container->get('root');

        return $container;
    }

    private function registeredContainer(int $count, string $purpose): Container
    {
        $container = new Container($this->alias($purpose));
        for ($index = 0; $index < $count; ++$index) {
            $container->bind(
                'service.' . $index,
                static fn(): int => 1,
                LifetimeEnum::Singleton,
                ['request-path'],
            );
        }

        return $container;
    }
}

final class RequestBenchLeaf
{
    public function value(): int
    {
        return 1;
    }
}

final readonly class RequestBenchMiddle
{
    public function __construct(public RequestBenchLeaf $leaf) {}
}

final readonly class RequestBenchRoot
{
    public function __construct(public RequestBenchMiddle $middle) {}

    public function handle(): int
    {
        return $this->middle->leaf->value();
    }
}

final readonly class RequestBenchController
{
    public function __construct(private RequestBenchRoot $root) {}

    public function handle(): int
    {
        return $this->root->handle();
    }
}
