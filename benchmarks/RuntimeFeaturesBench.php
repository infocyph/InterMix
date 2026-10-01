<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
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

    private int $macroSequence = 0;

    private string $serializedClosure;

    private string $signedSerializedClosure;

    private SignedClosureSerializer $signedSerializer;

    public function setUp(): void
    {
        $this->container = ContainerBuilder::create(
            '__runtime_benchmark__' . spl_object_id($this),
        )
            ->value('runtime.singleton', new RuntimeInvokable())
            ->build();
        $this->container->get('runtime.singleton');
        $this->invokable = new RuntimeInvokable();
        $this->closure = static fn(): int => 1;
        $this->serializedClosure = ClosureSerializer::serialize($this->closure);
        $this->signedSerializer = ClosureSerializer::signed('runtime-benchmark-key');
        $this->signedSerializedClosure = $this->signedSerializer->serialize($this->closure);

        RuntimeUnlockedMacros::macro('instanceMacro', fn(): int => 1);
        RuntimeUnlockedMacros::macro('staticMacro', static fn(): int => 1);
        RuntimeLockedMacros::macro('instanceMacro', fn(): int => 1);
        RuntimeLockedMacros::macro('staticMacro', static fn(): int => 1);
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
    public function benchContainerClassRegistration(): void
    {
        ContainerBuilder::create('__runtime_registration_benchmark__' . ++$this->macroSequence)
            ->autowire(
                RuntimeInvokable::class,
                RuntimeInvokable::class,
                arguments: ['sequence' => $this->macroSequence],
            );
    }

    #[BeforeMethods('setUp')]
    public function benchContainerHas(): void
    {
        $this->container->has('runtime.singleton');
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerClassDynamic(): void
    {
        $this->container->invoke($this->container->make(RuntimeInvokable::class));
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerClosure(): void
    {
        $this->container->invoke($this->closure);
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerFunction(): void
    {
        $this->container->invoke(__NAMESPACE__ . '\\runtimeBenchmarkFunction');
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerInvokableObject(): void
    {
        $this->container->invoke($this->invokable);
    }

    #[BeforeMethods('setUp')]
    public function benchInvokerStaticMethodString(): void
    {
        $this->container->invoke(RuntimeStaticTarget::class . '::run');
    }

    public function benchMacroBulkOverwriteLockDisabled(): void
    {
        RuntimeUnlockedMacros::mix(new RuntimeMixin());
    }

    public function benchMacroBulkOverwriteLockEnabled(): void
    {
        RuntimeLockedMacros::mix(new RuntimeMixin());
    }

    #[BeforeMethods('setUp')]
    public function benchMacroInstanceInvocation(): void
    {
        (new RuntimeUnlockedMacros())->instanceMacro();
    }

    #[BeforeMethods('setUp')]
    public function benchMacroInstanceInvocationLocked(): void
    {
        (new RuntimeLockedMacros())->instanceMacro();
    }

    public function benchMacroRegistrationLockDisabled(): void
    {
        RuntimeUnlockedMacros::macro('registered-' . ++$this->macroSequence, static fn(): int => 1);
    }

    public function benchMacroRegistrationLockEnabled(): void
    {
        RuntimeLockedMacros::macro('registered-' . ++$this->macroSequence, static fn(): int => 1);
    }

    #[BeforeMethods('setUp')]
    public function benchMacroStaticInvocation(): void
    {
        RuntimeUnlockedMacros::staticMacro();
    }

    #[BeforeMethods('setUp')]
    public function benchMacroStaticInvocationLocked(): void
    {
        RuntimeLockedMacros::staticMacro();
    }

    #[BeforeMethods('setUp')]
    public function benchNativeClosure(): void
    {
        ($this->closure)();
    }

    public function benchNativeFunction(): void
    {
        runtimeBenchmarkFunction();
    }

    #[BeforeMethods('setUp')]
    public function benchNativeInvokableObject(): void
    {
        ($this->invokable)();
    }

    public function benchNativeStaticMethod(): void
    {
        RuntimeStaticTarget::run();
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
    public function __construct(private readonly int $sequence = 0) {}

    public function __invoke(): int
    {
        return $this->sequence + 1;
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
