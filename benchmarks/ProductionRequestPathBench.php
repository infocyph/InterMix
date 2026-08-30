<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Fiber;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Iterations(5)]
#[Warmup(1)]
final class ProductionRequestPathBench
{
    private int $sequence = 0;

    private mixed $sink;

    #[Revs(100)]
    public function benchArtifactLoad(): void
    {
        static $generator;
        $generator ??= new StaticRuntimeGenerator();
        [$path, , $fallback] = $this->bootFixture();
        $this->sink = $generator->load($path, $fallback);
    }

    #[Revs(500)]
    public function benchCompiledControllerInvocation(): void
    {
        static $runtime;
        $runtime ??= $this->controllerRuntime();
        $this->sink = $runtime->resolveNow([ProductionRequestController::class, 'handle']);
    }

    #[Revs(500)]
    public function benchCompiledControllerRuntimeParameter(): void
    {
        static $runtime;
        $runtime ??= $this->controllerRuntime();
        $this->sink = $runtime->resolveNow(
            [ProductionRequestController::class, 'handle'],
            ['routeId' => 7],
        );
    }

    #[Revs(500)]
    public function benchCompiledPrivateInject(): void
    {
        static $runtime;
        $runtime ??= $this->propertyInjectRuntime();
        $this->sink = $runtime->get(ProductionRequestPrivateInject::class);
    }

    #[Revs(500)]
    public function benchCompiledPublicMethodPath(): void
    {
        static $runtime;
        $runtime ??= $this->methodRuntime(ProductionRequestCompiledMethod::class, 'compiled-method');
        $this->sink = $runtime->get('compiled-method');
    }

    #[Revs(1000)]
    public function benchCompiledScopedGraph(): void
    {
        static $runtime;
        if (!$runtime instanceof ProductionContainer) {
            $runtime = $this->threeNodeRuntime(LifetimeEnum::Scoped, 'scoped');
            $runtime->enterScope('request');
            $runtime->get('root');
        }
        $this->sink = $runtime->get('root');
    }

    #[Revs(1000)]
    public function benchCompiledScopedSeed(): void
    {
        static $runtime;
        if (!$runtime instanceof ProductionContainer) {
            $runtime = $this->threeNodeRuntime(LifetimeEnum::Scoped, 'scoped-seed');
            $runtime->enterScope('request', ['root' => new ProductionRequestRoot(
                new ProductionRequestMiddle(new ProductionRequestLeaf()),
            )]);
        }
        $this->sink = $runtime->get('root');
    }

    #[Revs(500)]
    public function benchCompiledScopeEnterResolveLeave(): void
    {
        static $runtime;
        $runtime ??= $this->threeNodeRuntime(LifetimeEnum::Scoped, 'scope-cycle');
        $runtime->enterScope('request');
        $this->sink = $runtime->get('root');
        $runtime->leaveScope();
    }

    #[Revs(500)]
    public function benchCompiledStaticMethodPath(): void
    {
        static $runtime;
        $runtime ??= $this->staticMethodRuntime();
        $this->sink = $runtime->get(ProductionRequestStaticMethod::class);
    }

    #[Revs(1000)]
    public function benchCompiledTaggedLazyPipeline(): void
    {
        static $runtime;
        $runtime ??= $this->taggedRuntime();
        $resolved = [];
        foreach ($runtime->findByTagLazy('middleware') as $id => $resolver) {
            $resolved[$id] = $resolver();
        }
        $this->sink = $resolved;
    }

    #[Revs(1000)]
    public function benchCompiledTaggedPipeline(): void
    {
        static $runtime;
        $runtime ??= $this->taggedRuntime();
        $this->sink = $runtime->findByTag('middleware');
    }

    #[Revs(1000)]
    public function benchCompiledTransientGraph(): void
    {
        static $runtime;
        $runtime ??= $this->threeNodeRuntime(LifetimeEnum::Transient, 'transient');
        $this->sink = $runtime->get('root');
    }

    #[Revs(500)]
    public function benchCompiledTransientGraph10(): void
    {
        static $runtime;
        $runtime ??= $this->tenNodeRuntime(LifetimeEnum::Transient, 'transient-10');
        $this->sink = $runtime->get(ProductionRequestNode1::class);
    }

    #[Revs(1000)]
    public function benchCompiledWarmSingleton(): void
    {
        static $runtime;
        if (!$runtime instanceof ProductionContainer) {
            $runtime = $this->threeNodeRuntime(LifetimeEnum::Singleton, 'singleton');
            $runtime->get('root');
        }
        $this->sink = $runtime->get('root');
    }

    #[Revs(500)]
    public function benchDynamicScopedFiber(): void
    {
        static $fiber;
        if (!$fiber instanceof Fiber) {
            $container = new Container($this->alias('dynamic-fiber'));
            $container->scoped('root', ProductionRequestRoot::class);
            $fiber = new Fiber(static function () use ($container): never {
                $container->enterScope('request');
                while (true) {
                    Fiber::suspend($container->get('root'));
                }
            });
            $this->sink = $fiber->start();

            return;
        }

        $this->sink = $fiber->resume();
    }

    #[Revs(500)]
    public function benchDynamicScopeEnterResolveLeave(): void
    {
        static $container;
        if (!$container instanceof Container) {
            $container = new Container($this->alias('dynamic-scope-cycle'));
            $container->scoped('root', ProductionRequestRoot::class);
        }
        $container->enterScope('request');
        $this->sink = $container->get('root');
        $container->leaveScope();
    }

    #[Revs(500)]
    public function benchFiberResumeBaseline(): void
    {
        static $fiber;
        if (!$fiber instanceof Fiber) {
            $fiber = new Fiber(static function (): never {
                while (true) {
                    Fiber::suspend(1);
                }
            });
            $this->sink = $fiber->start();

            return;
        }

        $this->sink = $fiber->resume();
    }

    #[Revs(500)]
    public function benchHybridFallbackGet(): void
    {
        static $runtime;
        $runtime ??= $this->hybridRuntime();
        $this->sink = $runtime->get('dynamic');
    }

    #[Revs(1000)]
    public function benchNativeTransientGraph(): void
    {
        $this->sink = new ProductionRequestRoot(
            new ProductionRequestMiddle(new ProductionRequestLeaf()),
        );
    }

    #[Revs(100)]
    public function benchPrevalidatedArtifactLoad(): void
    {
        static $generator;
        $generator ??= new StaticRuntimeGenerator();
        [$path, $sha256, $fallback] = $this->bootFixture();
        $this->sink = $generator->loadPrevalidated($path, $sha256, $fallback);
    }

    #[Revs(500)]
    public function benchProductionScopedFiber(): void
    {
        static $fiber;
        if (!$fiber instanceof Fiber) {
            $runtime = $this->threeNodeRuntime(LifetimeEnum::Scoped, 'production-fiber');
            $fiber = new Fiber(static function () use ($runtime): never {
                $runtime->enterScope('request');
                while (true) {
                    Fiber::suspend($runtime->get('root'));
                }
            });
            $this->sink = $fiber->start();

            return;
        }

        $this->sink = $fiber->resume();
    }

    #[Revs(500)]
    public function benchRuntimeIslandMethodPath(): void
    {
        static $runtime;
        $runtime ??= $this->methodRuntime(ProductionRequestRuntimeIsland::class, 'runtime-island');
        $this->sink = $runtime->get('runtime-island');
    }

    private function alias(string $purpose): string
    {
        return '__production_request_' . $purpose . '_' . (++$this->sequence);
    }

    private function artifactPath(): string
    {
        return sys_get_temp_dir() . '/intermix-production-request-' . bin2hex(random_bytes(8)) . '.php';
    }

    /** @return array{string, string, Container} */
    private function bootFixture(): array
    {
        static $fixture;
        if (is_array($fixture)) {
            return $fixture;
        }

        $builder = ContainerBuilder::create($this->alias('boot'))
            ->singleton('root', ProductionRequestRoot::class);
        $path = $this->artifactPath();
        $report = $builder->compile($path);
        $fallback = $builder->development();
        register_shutdown_function(static function () use ($path): void {
            foreach ([$path, $path . '.meta.json'] as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        });

        return $fixture = [$path, $report['sha256'], $fallback];
    }

    private function controllerRuntime(): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias('controller'))
            ->singleton(ProductionRequestLeaf::class)
            ->singleton(ProductionRequestMiddle::class)
            ->singleton(ProductionRequestRoot::class)
            ->transient(ProductionRequestController::class);
        $builder->registration()->registerMethod(ProductionRequestController::class, 'handle');

        return $this->production($builder);
    }

    private function hybridRuntime(): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias('hybrid'))
            ->singleton(ProductionRequestLeaf::class)
            ->bind('dynamic', static fn(): ProductionRequestLeaf => new ProductionRequestLeaf());

        return $this->production($builder);
    }

    private function methodRuntime(string $class, string $id): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias($id))
            ->singleton(ProductionRequestLeaf::class)
            ->transient($id, $class);

        return $this->production($builder);
    }

    private function production(ContainerBuilder $builder): ProductionContainer
    {
        $path = $this->artifactPath();
        $builder->compile($path);
        $runtime = $builder->production($path);
        $this->removeArtifact($path);

        return $runtime;
    }

    private function propertyInjectRuntime(): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias('private-inject'));
        $builder->options()->setOptions(propertyAttributes: true);
        $builder->singleton(ProductionRequestLeaf::class)
            ->transient(ProductionRequestPrivateInject::class);

        return $this->production($builder);
    }

    private function removeArtifact(string $path): void
    {
        foreach ([$path, $path . '.meta.json'] as $artifact) {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
    }

    private function staticMethodRuntime(): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias('static-method'))
            ->singleton(ProductionRequestLeaf::class)
            ->transient(ProductionRequestStaticMethod::class);
        $builder->registration()->registerMethod(ProductionRequestStaticMethod::class, 'boot');

        return $this->production($builder);
    }

    private function taggedRuntime(): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias('tags'))
            ->singleton('middleware.a', ProductionRequestMiddlewareA::class, ['middleware'])
            ->singleton('middleware.b', ProductionRequestMiddlewareB::class, ['middleware'])
            ->singleton('middleware.c', ProductionRequestMiddlewareC::class, ['middleware']);

        return $this->production($builder);
    }

    private function tenNodeRuntime(LifetimeEnum $lifetime, string $purpose): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias($purpose));
        foreach ([
            ProductionRequestNode1::class,
            ProductionRequestNode2::class,
            ProductionRequestNode3::class,
            ProductionRequestNode4::class,
            ProductionRequestNode5::class,
            ProductionRequestNode6::class,
            ProductionRequestNode7::class,
            ProductionRequestNode8::class,
            ProductionRequestNode9::class,
            ProductionRequestNode10::class,
        ] as $class) {
            $builder->bind($class, $class, $lifetime);
        }

        return $this->production($builder);
    }

    private function threeNodeRuntime(LifetimeEnum $lifetime, string $purpose): ProductionContainer
    {
        $builder = ContainerBuilder::create($this->alias($purpose));
        $builder->bind(ProductionRequestLeaf::class, ProductionRequestLeaf::class, $lifetime)
            ->bind(ProductionRequestMiddle::class, ProductionRequestMiddle::class, $lifetime)
            ->bind('root', ProductionRequestRoot::class, $lifetime);

        return $this->production($builder);
    }
}

final class ProductionRequestCompiledMethod
{
    public const string CALL_ON = 'boot';

    public int $value = 0;

    public function boot(ProductionRequestLeaf $leaf): void
    {
        $this->value = $leaf->value();
    }
}

final readonly class ProductionRequestController
{
    public function __construct(private ProductionRequestRoot $root) {}

    public function handle(int $routeId = 0): int
    {
        return $this->root->handle() + $routeId;
    }
}

final class ProductionRequestLeaf
{
    public function value(): int
    {
        return 1;
    }
}

final class ProductionRequestMiddlewareA {}

final class ProductionRequestMiddlewareB {}

final class ProductionRequestMiddlewareC {}

final readonly class ProductionRequestMiddle
{
    public function __construct(public ProductionRequestLeaf $leaf) {}
}

final readonly class ProductionRequestNode1
{
    public function __construct(public ProductionRequestNode2 $next) {}
}

final readonly class ProductionRequestNode2
{
    public function __construct(public ProductionRequestNode3 $next) {}
}

final readonly class ProductionRequestNode3
{
    public function __construct(public ProductionRequestNode4 $next) {}
}

final readonly class ProductionRequestNode4
{
    public function __construct(public ProductionRequestNode5 $next) {}
}

final readonly class ProductionRequestNode5
{
    public function __construct(public ProductionRequestNode6 $next) {}
}

final readonly class ProductionRequestNode6
{
    public function __construct(public ProductionRequestNode7 $next) {}
}

final readonly class ProductionRequestNode7
{
    public function __construct(public ProductionRequestNode8 $next) {}
}

final readonly class ProductionRequestNode8
{
    public function __construct(public ProductionRequestNode9 $next) {}
}

final readonly class ProductionRequestNode9
{
    public function __construct(public ProductionRequestNode10 $next) {}
}

final class ProductionRequestNode10 {}

final class ProductionRequestPrivateInject
{
    #[Inject]
    private ProductionRequestLeaf $leaf;

    public function value(): int
    {
        return $this->leaf->value();
    }
}

final readonly class ProductionRequestRoot
{
    public function __construct(public ProductionRequestMiddle $middle) {}

    public function handle(): int
    {
        return $this->middle->leaf->value();
    }
}

final class ProductionRequestRuntimeIsland
{
    public const string CALL_ON = 'boot';

    public int $value = 0;

    protected function boot(ProductionRequestLeaf $leaf): void
    {
        $this->value = $leaf->value();
    }
}

final class ProductionRequestStaticMethod
{
    public static int $value = 0;

    public static function boot(ProductionRequestLeaf $leaf): void
    {
        self::$value = $leaf->value();
    }
}
