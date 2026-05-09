<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
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

    private Invoker $invoker;

    private int $scopeCounter = 0;

    #[BeforeMethods('setUpContainer')]
    public function benchClosureCallWithDi(): void
    {
        $this->container->call(
            static fn(BenchService $service): int => $service->handle(1),
        );
    }

    #[BeforeMethods('setUpContainer')]
    public function benchEnvConditionalBindingPath(): void
    {
        $this->container->make(BenchEnvConsumer::class)->tick();
    }

    #[BeforeMethods('setUpContainer')]
    public function benchInvokerMethodInvoke(): void
    {
        $this->invoker->invoke([BenchMethodConsumer::class, 'handle'], ['value' => 1]);
    }

    public function benchManualObjectGraph(): void
    {
        (new BenchService(new BenchRepository(new BenchConfig(), new BenchLogger())))->handle(1);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchMethodWiringViaRegisterMethod(): void
    {
        $this->container->make(BenchMethodConsumer::class, 'handle');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchPropertyWiringViaRegisterProperty(): void
    {
        $this->container->make(BenchPropertyConsumer::class)->value();
    }

    #[BeforeMethods('setUpContainer')]
    public function benchResolveNowClass(): void
    {
        $this->container->resolveNow(BenchService::class);
    }

    #[BeforeMethods('setUpContainer')]
    public function benchResolveNowMethod(): void
    {
        $this->container->resolveNow(
            [BenchMethodConsumer::class, 'handle'],
            ['value' => 1],
        );
    }

    #[BeforeMethods('setUpContainer')]
    public function benchScopedLifetimeWithinScope(): void
    {
        $scope = 'scope-' . (++$this->scopeCounter);
        $this->container->enterScope($scope);
        $this->container->get('bench.scoped');
        $this->container->get('bench.scoped');
        $this->container->leaveScope();
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
        $this->container->findByTag('bench.pipeline.pre');
    }

    #[BeforeMethods('setUpContainer')]
    public function benchTransientMake(): void
    {
        $this->container->make(BenchService::class)->handle(1);
    }

    public function setUpContainer(): void
    {
        $this->container = new Container('__intermix_phpbench__' . spl_object_id($this));
        $this->container->options()->setOptions(injection: true)->end();
        $this->container->definitions()->bind(
            'bench.config',
            static fn(): BenchConfig => new BenchConfig(),
        );
        $this->container->definitions()->bind(
            'bench.scoped',
            static fn(): BenchScopedToken => new BenchScopedToken(),
            LifetimeEnum::Scoped,
        );
        $this->container->definitions()->bind(
            'bench.pipeline.a',
            static fn(): BenchPipelineA => new BenchPipelineA(),
            LifetimeEnum::Singleton,
            ['bench.pipeline.pre'],
        );
        $this->container->definitions()->bind(
            'bench.pipeline.b',
            static fn(): BenchPipelineB => new BenchPipelineB(),
            LifetimeEnum::Singleton,
            ['bench.pipeline.pre'],
        );
        $this->container->registration()->registerMethod(
            BenchMethodConsumer::class,
            'handle',
            ['value' => 1],
        );
        $this->container->registration()->registerProperty(
            BenchPropertyConsumer::class,
            ['seed' => 41],
        );
        $this->container->registration()->import(BenchServiceProvider::class);
        $this->container->options()->bindInterfaceForEnv(
            'bench',
            BenchClockInterface::class,
            BenchClockBench::class,
        );
        $this->container->setEnvironment('bench');
        $this->invoker = Invoker::with($this->container);

        // Warm paths so benchmark focuses on steady-state invocation overhead.
        $this->container->get('bench.config');
        $this->container->make(BenchService::class);
        $this->container->call(
            static fn(BenchService $service): int => $service->handle(1),
        );
        $this->container->make(BenchMethodConsumer::class, 'handle');
        $this->container->make(BenchPropertyConsumer::class)->value();
        $this->container->findByTag('bench.pipeline.pre');
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
    public function register(Container $container): void
    {
        $container->definitions()->bind(
            'bench.provider.service',
            static fn(BenchService $service): BenchProvidedService => new BenchProvidedService($service),
        );
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
