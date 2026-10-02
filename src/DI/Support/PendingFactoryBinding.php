<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\Exceptions\ContainerException;

final readonly class PendingFactoryBinding
{
    public function __construct(
        private ConfigurationContainer $container,
        private string $id,
        private Closure $factory,
    ) {}

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function register(array $tags = []): ConfigurationContainer
    {
        return $this->singleton($tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function scoped(array $tags = []): ConfigurationContainer
    {
        return $this->apply(LifetimeEnum::Scoped, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function singleton(array $tags = []): ConfigurationContainer
    {
        return $this->apply(LifetimeEnum::Singleton, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function transient(array $tags = []): ConfigurationContainer
    {
        return $this->apply(LifetimeEnum::Transient, $tags);
    }

    /**
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    private function apply(LifetimeEnum $lifetime, array $tags = []): ConfigurationContainer
    {
        return $this->container->bind(
            $this->id,
            new DirectFactory($this->factory, $this->container),
            $lifetime,
            $tags,
        );
    }
}
