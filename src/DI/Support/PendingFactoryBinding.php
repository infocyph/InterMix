<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\Exceptions\ContainerException;

final readonly class PendingFactoryBinding
{
    /**
     * Retain the closure and identifier until its registration lifetime is chosen.
     */
    public function __construct(
        private ConfigurationContainer $container,
        private string $id,
        private Closure $factory,
    ) {}

    /**
     * Bind the pending closure with the requested lifetime and tags.
     *
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function register(array $tags = []): ConfigurationContainer
    {
        return $this->singleton($tags);
    }

    /**
     * Register the pending closure once per active execution scope.
     *
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function scoped(array $tags = []): ConfigurationContainer
    {
        return $this->apply(LifetimeEnum::Scoped, $tags);
    }

    /**
     * Register the pending closure once per container lifetime.
     *
     * @param array<int, string> $tags
     * @throws ContainerException
     */
    public function singleton(array $tags = []): ConfigurationContainer
    {
        return $this->apply(LifetimeEnum::Singleton, $tags);
    }

    /**
     * Register the pending closure for fresh execution on every resolution.
     *
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
