<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;

final readonly class PendingFactoryBinding
{
    public function __construct(
        private Container $container,
        private string $id,
        private Closure $factory,
    ) {}

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function register(array $tags = []): Container
    {
        return $this->singleton($tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function scoped(array $tags = []): Container
    {
        return $this->apply(LifetimeEnum::Scoped, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function singleton(array $tags = []): Container
    {
        return $this->apply(LifetimeEnum::Singleton, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function transient(array $tags = []): Container
    {
        return $this->apply(LifetimeEnum::Transient, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    private function apply(LifetimeEnum $lifetime, array $tags = []): Container
    {
        $factory = $this->factory;
        $container = $this->container;

        return $this->container->bind(
            $this->id,
            static fn() => $factory($container),
            $lifetime,
            $tags,
        );
    }
}
