<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\ScopeContext;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Iterations(5)]
#[Warmup(1)]
final class StructuredScopeBench
{
    private mixed $sink;

    #[Revs(1000)]
    public function benchSequentialDynamicResolved(): void
    {
        $this->sink = $this->sequentialDynamic()->get('leaf');
    }

    #[Revs(1000)]
    public function benchSequentialCompiledResolved(): void
    {
        $this->sink = $this->sequentialCompiled()->get('leaf');
    }

    #[Revs(100)]
    public function benchFiberIsolatedScopeRoundTrip(): void
    {
        static $container;
        $container ??= $this->newDynamic('fiber-isolated');

        $fiber = new Fiber(static function () use ($container): object {
            $container->enterScope('request');
            try {
                return $container->get('leaf');
            } finally {
                $container->leaveScope();
            }
        });
        $fiber->start();
        $this->sink = $fiber->getReturn();
    }

    #[Revs(200)]
    public function benchDynamicCaptureContext(): void
    {
        [, $context] = $this->dynamicAttachmentFixture();
        $this->sink = $context;
    }

    #[Revs(100)]
    public function benchDynamicAttachedResolved(): void
    {
        [$container, $context] = $this->dynamicAttachmentFixture();
        $fiber = new Fiber(static fn(): object => $container->withinScopeContext(
            $context,
            static fn(Container $active): object => $active->get('leaf'),
        ));
        $fiber->start();
        $this->sink = $fiber->getReturn();
    }

    #[Revs(100)]
    public function benchCompiledAttachedResolved(): void
    {
        [$container, $context] = $this->compiledAttachmentFixture();
        $fiber = new Fiber(static fn(): object => $container->withinScopeContext(
            $context,
            static fn(ProductionContainer $active): object => $active->get('leaf'),
        ));
        $fiber->start();
        $this->sink = $fiber->getReturn();
    }

    #[Revs(100)]
    public function benchDynamicAttachedNestedRoundTrip(): void
    {
        [$container, $context] = $this->dynamicAttachmentFixture();
        $fiber = new Fiber(static fn(): object => $container->withinScopeContext(
            $context,
            static function (Container $active): object {
                $active->enterScope('nested');
                try {
                    return $active->get('leaf');
                } finally {
                    $active->leaveScope();
                }
            },
        ));
        $fiber->start();
        $this->sink = $fiber->getReturn();
    }

    /** @return array{Container, ScopeContext} */
    private function dynamicAttachmentFixture(): array
    {
        static $fixture;
        if (!is_array($fixture)) {
            $container = $this->newDynamic('attached');
            $container->enterScope('request');
            $container->get('leaf');
            $fixture = [$container, $container->captureScopeContext()];
        }

        return $fixture;
    }

    /** @return array{ProductionContainer, ScopeContext} */
    private function compiledAttachmentFixture(): array
    {
        static $fixture;
        if (!is_array($fixture)) {
            $container = $this->newCompiled('attached');
            $container->enterScope('request');
            $container->get('leaf');
            $fixture = [$container, $container->captureScopeContext()];
        }

        return $fixture;
    }

    private function sequentialDynamic(): Container
    {
        static $container;
        if (!$container instanceof Container) {
            $container = $this->newDynamic('sequential');
            $container->enterScope('request');
            $container->get('leaf');
        }

        return $container;
    }

    private function sequentialCompiled(): ProductionContainer
    {
        static $container;
        if (!$container instanceof ProductionContainer) {
            $container = $this->newCompiled('sequential');
            $container->enterScope('request');
            $container->get('leaf');
        }

        return $container;
    }

    private function newDynamic(string $purpose): Container
    {
        $container = new Container('__structured_scope_bench_' . $purpose . '_' . bin2hex(random_bytes(4)));
        $container->scoped('leaf', StructuredScopeBenchLeaf::class);

        return $container;
    }

    private function newCompiled(string $purpose): ProductionContainer
    {
        $builder = ContainerBuilder::create('__structured_scope_bench_compiled_' . $purpose . '_' . bin2hex(random_bytes(4)));
        $builder->scoped('leaf', StructuredScopeBenchLeaf::class);
        $path = sys_get_temp_dir() . '/intermix-structured-scope-bench-' . bin2hex(random_bytes(8)) . '.php';
        $builder->compile($path);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
            @unlink($path . '.meta.json');
        });

        return $builder->production($path);
    }
}

final class StructuredScopeBenchLeaf {}
