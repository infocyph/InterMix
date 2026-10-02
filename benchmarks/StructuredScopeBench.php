<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Iterations(5)]
#[Warmup(1)]
final class StructuredScopeBench
{
    private mixed $sink;

    #[Revs(100)]
    public function benchCompiledAttachedResolved(): void
    {
        static $container;
        $container ??= $this->newCompiled('attached');
        $this->sink = $container->withinScope(
            'request',
            static function (ProductionContainer $owner): object {
                $owner->get('leaf');
                $context = $owner->captureScopeContext();
                $fiber = new Fiber(static fn(): object => $owner->withinScopeContext(
                    $context,
                    static fn(ProductionContainer $active): object => $active->get('leaf'),
                ));
                $fiber->start();

                return $fiber->getReturn();
            },
        );
    }

    #[Revs(100)]
    public function benchDynamicAttachedNestedRoundTrip(): void
    {
        static $container;
        $container ??= $this->newDynamic('attached-nested');
        $this->sink = $container->withinScope(
            'request',
            static function (Container $owner): object {
                $owner->get('leaf');
                $context = $owner->captureScopeContext();
                $fiber = new Fiber(static fn(): object => $owner->withinScopeContext(
                    $context,
                    static fn(Container $active): object => $active->withinScope(
                        'nested',
                        static fn(Container $nested): object => $nested->get('leaf'),
                    ),
                ));
                $fiber->start();

                return $fiber->getReturn();
            },
        );
    }

    #[Revs(100)]
    public function benchDynamicAttachedResolved(): void
    {
        static $container;
        $container ??= $this->newDynamic('attached');
        $this->sink = $container->withinScope(
            'request',
            static function (Container $owner): object {
                $owner->get('leaf');
                $context = $owner->captureScopeContext();
                $fiber = new Fiber(static fn(): object => $owner->withinScopeContext(
                    $context,
                    static fn(Container $active): object => $active->get('leaf'),
                ));
                $fiber->start();

                return $fiber->getReturn();
            },
        );
    }

    #[Revs(200)]
    public function benchDynamicCaptureContext(): void
    {
        static $container;
        $container ??= $this->newDynamic('capture');
        $this->sink = $container->withinScope(
            'request',
            static fn(Container $active): ScopeContext => $active->captureScopeContext(),
        );
    }

    #[Revs(100)]
    public function benchFiberIsolatedScopeRoundTrip(): void
    {
        static $container;
        $container ??= $this->newDynamic('fiber-isolated');

        $fiber = new Fiber(
            static fn(): object => $container->withinScope(
                'request',
                static fn(Container $active): object => $active->get('leaf'),
            ),
        );
        $fiber->start();
        $this->sink = $fiber->getReturn();
    }

    #[Revs(1000)]
    public function benchSequentialCompiledResolved(): void
    {
        $this->sink = $this->sequentialCompiled()->withinScope(
            'request',
            static fn(ProductionContainer $active): object => $active->get('leaf'),
        );
    }

    #[Revs(1000)]
    public function benchSequentialDynamicResolved(): void
    {
        $this->sink = $this->sequentialDynamic()->withinScope(
            'request',
            static fn(Container $active): object => $active->get('leaf'),
        );
    }

    private function newCompiled(string $purpose): ProductionContainer
    {
        $builder = ContainerBuilder::create('__structured_scope_bench_compiled_' . $purpose . '_' . bin2hex(random_bytes(4)));
        $builder->autowire('leaf', StructuredScopeBenchLeaf::class, lifetime: LifetimeEnum::Scoped);
        $path = sys_get_temp_dir() . '/intermix-structured-scope-bench-' . bin2hex(random_bytes(8)) . '.php';
        $builder->compile($path);
        register_shutdown_function(static function () use ($path): void {
            foreach ([$path, $path . '.meta.json'] as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        });

        return $builder->production($path);
    }

    private function newDynamic(string $purpose): Container
    {
        return ContainerBuilder::create(
            '__structured_scope_bench_' . $purpose . '_' . bin2hex(random_bytes(4)),
        )
            ->autowire('leaf', StructuredScopeBenchLeaf::class, lifetime: LifetimeEnum::Scoped)
            ->build();
    }

    private function sequentialCompiled(): ProductionContainer
    {
        static $container;
        if (!$container instanceof ProductionContainer) {
            $container = $this->newCompiled('sequential');
        }

        return $container;
    }

    private function sequentialDynamic(): Container
    {
        static $container;
        if (!$container instanceof Container) {
            $container = $this->newDynamic('sequential');
        }

        return $container;
    }
}

final class StructuredScopeBenchLeaf {}
