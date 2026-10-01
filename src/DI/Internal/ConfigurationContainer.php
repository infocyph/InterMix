<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Resolver\Repository;
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

    /** @param array<string, mixed> $instances */
    public function assertValidScopeSeeds(array $instances): void
    {
        parent::validateScopeSeeds($instances);
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

    public function call(string|Closure|callable $target, string|bool|null $method = null): mixed
    {
        if ($target instanceof Closure || is_callable($target)) {
            return $this->invoke($target);
        }

        $service = $this->get($target);
        if (!is_string($method) || $method === '') {
            return $service;
        }
        if (!is_object($service) || !is_callable([$service, $method])) {
            throw new \Infocyph\InterMix\Exceptions\ContainerException(
                "Method {$target}::{$method}() does not exist.",
            );
        }

        return $this->invoke(Closure::fromCallable([$service, $method]));
    }

    /** @return null|array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>} */
    public function compilationReport(): ?array
    {
        return parent::compilationReport();
    }

    public function compileTo(string $path, bool $load = false): self
    {
        parent::compileTo($path, $load);

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

    /** @param array<string, mixed> $instances */
    public function enterScope(string $scope, array $instances = []): self
    {
        parent::enterScope($scope, $instances);

        return $this;
    }

    /** @return array<string, mixed> */
    public function exportGraph(?string $warmFromId = null, bool $clear = false): array
    {
        return parent::exportGraph($warmFromId, $clear);
    }

    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return parent::factory($id, $factory);
    }

    /** @return array<string, mixed> */
    public function findByTag(string $tag): array
    {
        return parent::findByTag($tag);
    }

    /** @return iterable<string, callable(): mixed> */
    public function findByTagLazy(string $tag): iterable
    {
        return parent::findByTagLazy($tag);
    }

    public function forkConfigurationRuntime(bool $locked = true): self
    {
        $runtime = new self($this->instanceAlias);
        $this->copyConfigurationInto($runtime, $locked);

        return $runtime;
    }

    public function get(string $id): mixed
    {
        try {
            return $this->invocation()->getLegacy($id);
        } catch (\Infocyph\InterMix\Exceptions\NotFoundException|\Infocyph\InterMix\Exceptions\ContainerException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if ($this->repository->isOnMissingFailure($throwable)) {
                throw $throwable;
            }

            throw new \Infocyph\InterMix\Exceptions\ContainerException(
                "Resolution failed for '$id': {$throwable->getMessage()}",
                previous: $throwable,
            );
        }
    }

    public function getCurrentResolver(): \Infocyph\InterMix\DI\Invoker\CompiledCall|InjectedCall|GenericCall
    {
        return parent::getCurrentResolver();
    }

    public function getRepository(): Repository
    {
        return parent::getRepository();
    }

    public function getReturn(string $id): mixed
    {
        return $this->invocation()->getReturnLegacy($id);
    }

    public function has(string $id): bool
    {
        return $this->invocation()->hasLegacy($id);
    }

    public function invocation(): \Infocyph\InterMix\DI\Managers\InvocationManager
    {
        return parent::invocation();
    }

    public function isResolved(string $id): bool
    {
        return parent::isResolved($id);
    }

    public function leaveScope(): self
    {
        parent::leaveScope();

        return $this;
    }

    public function lock(): self
    {
        parent::lock();

        return $this;
    }

    public function onMissing(callable $callback): self
    {
        parent::onMissing($callback);

        return $this;
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

    /**
     * @param array<array-key, mixed>|string|Closure|callable|null $spec
     * @param array<int|string, mixed> $parameters
     */
    public function resolveNow(string|Closure|callable|array|null $spec, array $parameters = []): mixed
    {
        if ($spec === null) {
            return $this;
        }
        if (is_callable($spec)) {
            return $this->invoke($spec, $parameters);
        }
        if (is_string($spec) && class_exists($spec)) {
            return $this->make($spec, $parameters);
        }

        throw new \InvalidArgumentException('Expected a native callable or class-string.');
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

    /** @param class-string<InjectedCall|GenericCall> $resolverClass */
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

    public function strictGet(string $id): mixed
    {
        return parent::get($id);
    }

    public function strictHas(string $id): bool
    {
        return parent::has($id);
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

    public function useCompiled(string $path): self
    {
        parent::useCompiled($path);

        return $this;
    }

    public function usePrevalidated(string $path, string $fingerprint): self
    {
        parent::usePrevalidated($path, $fingerprint);

        return $this;
    }

    /** @return array<int, string> */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        return parent::validate($strict, $resolveFactories);
    }

    public function value(string $id, mixed $value): self
    {
        parent::value($id, $value);

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        return parent::when($consumer);
    }

    /** @param array<string, mixed> $instances */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);

        try {
            return $callback($this);
        } finally {
            $this->leaveScope();
        }
    }
}
