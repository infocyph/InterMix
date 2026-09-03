<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class StaticRuntimeContainerInterfaceConsumer
{
    public function __construct(public ContainerInterface $container) {}
}

final class StaticRuntimeUserContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new RuntimeException("Unknown service: {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }
}

function staticRuntimeContainerInterfaceArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-container-interface-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticRuntimeContainerInterfaceArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles the intrinsic container interface as the generated production container', function () {
    $builder = ContainerBuilder::create(uniqid('container_interface_'))
        ->singleton(StaticRuntimeContainerInterfaceConsumer::class);
    $path = staticRuntimeContainerInterfaceArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $consumer = $runtime->get(StaticRuntimeContainerInterfaceConsumer::class);

        expect($report['skipped'])->toBe([])
            ->and($report['compiled'])->toContain(
                ContainerInterface::class,
                StaticRuntimeContainerInterfaceConsumer::class,
            )
            ->and($runtime->get(ContainerInterface::class))->toBe($runtime)
            ->and($consumer)->toBeInstanceOf(StaticRuntimeContainerInterfaceConsumer::class)
            ->and($consumer->container)->toBe($runtime);
    } finally {
        removeStaticRuntimeContainerInterfaceArtifact($path);
    }
});

it('does not treat a user-rebound container interface as the intrinsic container', function () {
    $container = new StaticRuntimeUserContainer();
    $builder = ContainerBuilder::create(uniqid('user_container_interface_'))
        ->value(ContainerInterface::class, $container);
    $path = staticRuntimeContainerInterfaceArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['skipped'])->toHaveKey(ContainerInterface::class)
            ->and($runtime->get(ContainerInterface::class))->toBe($container);
    } finally {
        removeStaticRuntimeContainerInterfaceArtifact($path);
    }
});
