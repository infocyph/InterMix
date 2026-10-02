<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Revs(200)]
#[Iterations(5)]
#[Warmup(1)]
final class IntermixBench
{
    private Container $container;

    private Closure $diHandler;

    private int $scopeCounter = 0;

    private Closure $zeroArgumentHandler;

    #[BeforeMethods('setUpContainer')]
    public function benchClosureCallWithDi(): void
    {
        $this->container->invoke($this->diHandler);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchContainerHasHotPath(): void
    {
        $this->container->has('bench.config');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchDirectFactoryTransientResolution(): void
    {
        $this->container->get('bench.factory.direct');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchEnvConditionalBindingPath(): void
    {
        $this->container->make(BenchEnvConsumer::class)->tick();
    }

    #[BeforeMethods('setUpContainer')]
    public function benchInvokerMethodInvoke(): void
    {
        $target = $this->container->make(BenchMethodConsumer::class);
        $this->container->invoke([$target, 'handle'], ['value' => 1]);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchInvokerStaticMethodInvoke(): void
    {
        $this->container->invoke([BenchStaticMethodConsumer::class, 'handle'], ['value' => 1]);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchInvokerZeroArgumentClosure(): void
    {
        $this->container->invoke($this->zeroArgumentHandler);
    }

    public function benchManualObjectGraph(): void
    {
        (new BenchService(new BenchRepository(new BenchConfig(), new BenchLogger())))->handle(1);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchMethodWiringViaRegisterMethod(): void
    {
        $target = $this->container->make(BenchMethodConsumer::class);
        $this->container->invoke([$target, 'handle'], ['value' => 1]);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchPropertyWiringViaRegisterProperty(): void
    {
        $this->container->get(BenchPropertyConsumer::class)->value();
    }

    #[BeforeMethods('setUpContainer')]
    public function benchReflectedFactoryTransientResolution(): void
    {
        $this->container->get('bench.factory.reflected');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchResolveNowClass(): void
    {
        $this->container->make(BenchService::class);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchResolveNowMethod(): void
    {
        $target = $this->container->make(BenchMethodConsumer::class);
        $this->container->invoke([$target, 'handle'], ['value' => 1]);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchScopedLifetimeWithinScope(): void
    {
        $scope = 'scope-' . (++$this->scopeCounter);
        $this->container->withinScope($scope, static function (Container $active): void {
            $active->get('bench.scoped');
            $active->get('bench.scoped');
        });
    }

    #[BeforeMethods('setUpContainer')]
    public function benchSeededScopeWithinScope(): void
    {
        $scope = 'seeded-scope-' . (++$this->scopeCounter);
        $seeded = new BenchScopedToken();
        $this->container->withinScope(
            $scope,
            static fn(Container $container): BenchScopedToken => $container->get('bench.scoped'),
            ['bench.scoped' => $seeded],
        );
    }

    #[BeforeMethods('setUpContainer')]
    public function benchServiceProviderPath(): void
    {
        $this->container->get('bench.provider.service');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchSingletonGetHotPath(): void
    {
        $this->container->get('bench.config');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchTaggedLookupFindByTag(): void
    {
        iterator_to_array($this->container->tagged('bench.pipeline.pre'));
    }

    #[BeforeMethods('setUpContainer')]
    public function benchTaggedLookupLazy(): void
    {
        foreach ($this->container->tagged('bench.pipeline.pre') as $service) {
            $service::class;

            break;
        }
    }

    #[BeforeMethods('setUpContainer')]
    public function benchTransientMake(): void
    {
        $this->container->make(BenchService::class)->handle(1);
    }

    public function setUpContainer(): void
    {
        $builder = ContainerBuilder::create('__intermix_phpbench__' . spl_object_id($this));
        $builder->autowire('bench.config', BenchConfig::class)
            ->factory(
                'bench.factory.direct',
                static fn(Container $runtime): BenchFactoryProduct => new BenchFactoryProduct($runtime),
                lifetime: LifetimeEnum::Transient,
            )
            ->factory(
                'bench.factory.reflected',
                static fn(Container $runtime): BenchFactoryProduct => new BenchFactoryProduct($runtime),
                lifetime: LifetimeEnum::Transient,
            )
            ->autowire(
                'bench.scoped',
                BenchScopedToken::class,
                lifetime: LifetimeEnum::Scoped,
            )
            ->autowire(
                'bench.pipeline.a',
                BenchPipelineA::class,
                tags: ['bench.pipeline.pre'],
            )
            ->autowire(
                'bench.pipeline.b',
                BenchPipelineB::class,
                tags: ['bench.pipeline.pre'],
            )
            ->autowire(BenchMethodConsumer::class, BenchMethodConsumer::class)
            ->autowire(
                BenchPropertyConsumer::class,
                BenchPropertyConsumer::class,
                properties: ['seed' => 41],
            )
            ->import(new BenchServiceProvider())
            ->bindInterfaceForEnv(
                'bench',
                BenchClockInterface::class,
                BenchClockBench::class,
            )
            ->setEnvironment('bench');

        $this->container = $builder->build();
        $this->diHandler = static fn(BenchService $service): int => $service->handle(1);
        $this->zeroArgumentHandler = static fn(): int => 1;

        // Warm paths so benchmark focuses on steady-state invocation overhead.
        $this->container->get('bench.config');
        $this->container->get('bench.factory.direct');
        $this->container->get('bench.factory.reflected');
        $this->container->make(BenchService::class);
        $this->container->invoke($this->diHandler);
        $target = $this->container->make(BenchMethodConsumer::class);
        $this->container->invoke([$target, 'handle'], ['value' => 1]);
        $this->container->get(BenchPropertyConsumer::class)->value();
        iterator_to_array($this->container->tagged('bench.pipeline.pre'));
        $this->container->get('bench.provider.service');
        $this->container->make(BenchEnvConsumer::class)->tick();
    }
}

final readonly class BenchConfig
{
    public function __construct(
        public string $env = 'benchmark',
    ) {}
}

final readonly class BenchFactoryProduct
{
    public function __construct(
        public Container $container,
    ) {}
}

final class BenchLogger
{
    public function log(string $message): void {}
}

final readonly class BenchRepository
{
    public function __construct(
        public BenchConfig $config,
        public BenchLogger $logger,
    ) {}
}

final readonly class BenchService
{
    public function __construct(
        public BenchRepository $repository,
    ) {}

    public function handle(int $value): int
    {
        return $value + 1;
    }
}

final readonly class BenchMethodConsumer
{
    public function __construct(
        private BenchService $service,
    ) {}

    public function handle(int $value = 1): int
    {
        return $this->service->handle($value);
    }
}

final class BenchStaticMethodConsumer
{
    public static function handle(int $value = 1): int
    {
        return $value + 1;
    }
}

final class BenchPropertyConsumer
{
    public int $seed = 0;

    public function value(): int
    {
        return $this->seed + 1;
    }
}

final class BenchScopedToken
{
    private static int $counter = 0;

    public int $id;

    public function __construct()
    {
        $this->id = ++self::$counter;
    }
}

final class BenchPipelineA {}

final class BenchPipelineB {}

final readonly class BenchProvidedService
{
    public function __construct(
        private BenchService $service,
    ) {}

    public function run(): int
    {
        return $this->service->handle(1);
    }
}

final class BenchServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire('bench.provider.service', BenchProvidedService::class);
    }
}

interface BenchClockInterface
{
    public function now(): int;
}

final class BenchClockBench implements BenchClockInterface
{
    public function now(): int
    {
        return 1;
    }
}

final readonly class BenchEnvConsumer
{
    public function __construct(
        private BenchClockInterface $clock,
    ) {}

    public function tick(): int
    {
        return $this->clock->now();
    }
}
