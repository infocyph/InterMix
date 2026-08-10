<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use Infocyph\InterMix\Serializer\SignedClosureSerializer;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

function runtimeBenchmarkFunction(): int
{
    return 1;
}

#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class RuntimeFeaturesBench
{
    private Closure $closure;

    private Container $container;

    private RuntimeInvokable $invokable;

    private Invoker $invoker;

    private string $serializedClosure;

    private string $signedSerializedClosure;

    private SignedClosureSerializer $signedSerializer;

    public function setUp(): void
    {
        $this->container = new Container('__runtime_benchmark__' . spl_object_id($this));
        $this->container->singleton('runtime.singleton', new RuntimeInvokable());
        $this->container->get('runtime.singleton');
        $this->invoker = Invoker::with($this->container);
        $this->invokable = new RuntimeInvokable();
        $this->closure = static fn(): int => 1;
        $this->serializedClosure = ClosureSerializer::serialize($this->closure);
        $this->signedSerializer = ClosureSerializer::signed('runtime-benchmark-key');
        $this->signedSerializedClosure = $this->signedSerializer->serialize($this->closure);

        RuntimeUnlockedMacros::macro('instanceMacro', fn(): int => 1);
        RuntimeUnlockedMacros::macro('staticMacro', static fn(): int => 1);
    }

    #[BeforeMethods('setUp')]
    public function benchClosureSerialize(): void
    {
        ClosureSerializer::serialize($this->closure);
    }

    #[BeforeMethods('setUp')]
    public function benchClosureUnserialize(): void
    {
        ClosureSerializer::unserialize($this->serializedClosure);
    }

    #[BeforeMethods('setUp')]
    public function benchContainerHas(): void
    {
        $this->container->has('runtime.singleton');
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerClass(): void
    {
        $this->invoker->invoke(RuntimeInvokable::class);
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerFunction(): void
    {
        $this->invoker->invoke(__NAMESPACE__ . '\\runtimeBenchmarkFunction');
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerInvokableObject(): void
    {
        $this->invoker->invoke($this->invokable);
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerSerializedClosure(): void
    {
        $this->invoker->invoke($this->serializedClosure);
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerStaticMethodString(): void
    {
        $this->invoker->invoke(RuntimeStaticTarget::class . '::run');
    }

    public function benchMacroBulkMixLockDisabled(): void
    {
        RuntimeUnlockedMacros::mix(new RuntimeMixin());
    }

    public function benchMacroBulkMixLockEnabled(): void
    {
        RuntimeLockedMacros::mix(new RuntimeMixin());
    }

    #[BeforeMethods('setUp')]
    public function benchMacroInstanceInvocation(): void
    {
        (new RuntimeUnlockedMacros())->instanceMacro();
    }

    public function benchMacroRegistrationLockDisabled(): void
    {
        RuntimeUnlockedMacros::macro('registered', static fn(): int => 1);
    }

    public function benchMacroRegistrationLockEnabled(): void
    {
        RuntimeLockedMacros::macro('registered', static fn(): int => 1);
    }

    #[BeforeMethods('setUp')]
    public function benchMacroStaticInvocation(): void
    {
        RuntimeUnlockedMacros::staticMacro();
    }

    #[BeforeMethods('setUp')]
    public function benchSignedClosureSerialize(): void
    {
        $this->signedSerializer->serialize($this->closure);
    }

    #[BeforeMethods('setUp')]
    public function benchSignedClosureUnserialize(): void
    {
        $this->signedSerializer->unserialize($this->signedSerializedClosure);
    }
}

final class RuntimeInvokable
{
    public function __invoke(): int
    {
        return 1;
    }
}

final class RuntimeLockedMacros
{
    use MacroMix;

    public const ENABLE_LOCK = true;
}

final class RuntimeMixin
{
    public function first(): int
    {
        return 1;
    }

    public function second(): int
    {
        return 2;
    }
}

final class RuntimeStaticTarget
{
    public static function run(): int
    {
        return 1;
    }
}

final class RuntimeUnlockedMacros
{
    use MacroMix;
}
