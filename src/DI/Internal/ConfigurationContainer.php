<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Support\ContextualBindingBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PendingFactoryBinding;

/**
 * Mutable builder-owned configuration host.
 *
 * @internal
 */
final class ConfigurationContainer extends Container
{
    public function alias(
        string $id,
        string $target,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
    ): self {
        parent::alias($id, $target, $lifetime);

        return $this;
    }

    public function attributeRegistry(): AttributeRegistry
    {
        return parent::attributeRegistry();
    }

    /** @param array<int, string> $tags */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        parent::bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        parent::bindFactory($id, $factory, $lifetime, $tags);

        return $this;
    }

    public function definitions(): DefinitionManager
    {
        return parent::definitions();
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        parent::enableLazyLoading($lazy);

        return $this;
    }

    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return parent::factory($id, $factory);
    }

    public function onResolved(string $id, callable $callback): self
    {
        parent::onResolved($id, $callback);

        return $this;
    }

    public function onResolving(string $id, callable $callback): self
    {
        parent::onResolving($id, $callback);

        return $this;
    }

    public function onScopeLeave(string $scope, callable $callback): self
    {
        parent::onScopeLeave($scope, $callback);

        return $this;
    }

    public function options(): OptionsManager
    {
        return parent::options();
    }

    public function registration(): RegistrationManager
    {
        return parent::registration();
    }

    /** @param array<int, string> $tags */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::scoped($id, $definition, $tags);

        return $this;
    }

    public function setEnvironment(string $env): self
    {
        parent::setEnvironment($env);

        return $this;
    }

    /** @param class-string<\Infocyph\InterMix\DI\Invoker\InjectedCall|\Infocyph\InterMix\DI\Invoker\GenericCall> $resolverClass */
    public function setResolverClass(string $resolverClass): void
    {
        parent::setResolverClass($resolverClass);
    }

    /** @param array<int, string> $tags */
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::singleton($id, $definition, $tags);

        return $this;
    }

    /** @param array<int, string> $tags */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::transient($id, $definition, $tags);

        return $this;
    }

    public function unbind(string $id): self
    {
        parent::unbind($id);

        return $this;
    }

    public function value(string $id, mixed $value): self
    {
        parent::value($id, $value);

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        return parent::when($consumer);
    }    public function forkConfigurationRuntime(bool $locked = true): self
    {
        $runtime = new self($this->instanceAlias);
        $this->copyConfigurationInto($runtime, $locked);

        return $runtime;
    }


}
