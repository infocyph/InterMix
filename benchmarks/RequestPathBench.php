<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
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
        $this->sink = ContainerBuilder::create($this->alias('construct'))->build();
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
        $container = $this->graphBuilder($this->alias('first-graph'))->build();
        $this->sink = $container->get(RequestBenchRoot::class);
    }

    #[Revs(100)]
    public function benchFirstDiClosure(): void
    {
        $container = $this->graphBuilder($this->alias('first-closure'))->build();
        $handler = static fn(RequestBenchRoot $root): int => $root->handle();
        $this->sink = $container->call($handler);
    }

    #[Revs(100)]
    public function benchFirstMethodInvocation(): void
    {
        $container = $this->graphBuilder($this->alias('first-method'), includeController: true)->build();
        $this->sink = $container->call(RequestBenchController::class, 'handle');
    }

    #[Revs(100)]
    public function benchFirstScopedResolution(): void
    {
        $container = $this->graphBuilder(
            $this->alias('first-scope'),
            LifetimeEnum::Scoped,
        )->build();
        $this->sink = $container->withinScope(
            'request',
            static fn(Container $active): object => $active->get('root'),
        );
    }

    #[Revs(100)]
    public function benchFirstSingletonResolution(): void
    {
        $container = $this->graphBuilder($this->alias('first-singleton'))
            ->alias('service', RequestBenchRoot::class)
            ->build();
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
            $container = $this->graphBuilder(
                $this->alias('hot-scope'),
                LifetimeEnum::Scoped,
            )->build();
            $container->withinScope(
                'request',
                static fn(Container $active): object => $active->get('root'),
            );
        }
        $this->sink = $container->withinScope(
            'request',
            static fn(Container $active): object => $active->get('root'),
        );
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

    private function graphBuilder(
        string $alias,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        bool $includeController = false,
    ): ContainerBuilder {
        $builder = ContainerBuilder::create($alias)
            ->autowire(RequestBenchLeaf::class, RequestBenchLeaf::class, lifetime: $lifetime)
            ->autowire(RequestBenchMiddle::class, RequestBenchMiddle::class, lifetime: $lifetime)
            ->autowire(RequestBenchRoot::class, RequestBenchRoot::class, lifetime: $lifetime)
            ->alias('root', RequestBenchRoot::class);

        if ($includeController) {
            $builder->autowire(
                RequestBenchController::class,
                RequestBenchController::class,
                lifetime: $lifetime,
            );
        }

        return $builder;
    }

    private function hotContainer(): Container
    {
        $container = $this->graphBuilder($this->alias('hot'))->build();
        $container->get('root');

        return $container;
    }

    private function registeredContainer(int $count, string $purpose): Container
    {
        $builder = ContainerBuilder::create($this->alias($purpose));
        for ($index = 0; $index < $count; ++$index) {
            $builder->autowire(
                'service.' . $index,
                RequestBenchLeaf::class,
                tags: ['request-path'],
            );
        }

        return $builder->build();
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
