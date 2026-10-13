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
    /**
     * Bind an identifier to another entry using the requested lifetime.
     */
    public function alias(
        string $id,
        string $target,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
    ): self {
        parent::alias($id, $target, $lifetime);

        return $this;
    }

    /**
     * Validate fallback seeds against declared scoped entries before scope attachment.
     *
     * @param array<string, mixed> $instances
     */
    public function assertValidScopeSeeds(array $instances): void
    {
        parent::validateScopeSeeds($instances);
    }

    /**
     * Access container-owned attribute registrations used during dependency injection.
     */
    public function attributeRegistry(): AttributeRegistry
    {
        return parent::attributeRegistry();
    }

    /**
     * Register a definition with lifetime and tags; locked configuration rejects mutations.
     *
     * @param array<int, string> $tags
     */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        parent::bind($id, $definition, $lifetime, $tags);

        return $this;
    }

    /**
     * Wrap a runtime closure for direct invocation with this configuration container.
     *
     * @param array<int, string> $tags
     */
    public function bindFactory(
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        parent::bindFactory($id, $factory, $lifetime, $tags);

        return $this;
    }

    /**
     * Invoke a callable or resolve a service and optionally invoke its named method.
     */
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

    /**
     * Expose the most recent compilation result, or null before compilation.
     *
     * @return null|array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>}
     */
    public function compilationReport(): ?array
    {
        return parent::compilationReport();
    }

    /**
     * Write the legacy compiled resolver and optionally activate it on this configuration.
     */
    public function compileTo(string $path, bool $load = false): self
    {
        parent::compileTo($path, $load);

        return $this;
    }

    /**
     * Reuse the definition manager; validate the configuration host only on first use.
     */
    public function definitions(): DefinitionManager
    {
        return parent::definitions();
    }

    /**
     * Control how future class bindings are stored without eagerly resolving existing entries.
     */
    public function enableLazyLoading(bool $lazy = true): self
    {
        parent::enableLazyLoading($lazy);

        return $this;
    }

    /**
     * Push an execution scope with supplied seeds; scope names cannot repeat in its ancestry.
     *
     * @param array<string, mixed> $instances
     */
    public function enterScope(string $scope, array $instances = []): self
    {
        parent::enterScope($scope, $instances);

        return $this;
    }

    /**
     * Return the traced dependency graph, optionally resolving an entry before exporting.
     *
     * @return array<string, mixed>
     */
    public function exportGraph(?string $warmFromId = null, bool $clear = false): array
    {
        return parent::exportGraph($warmFromId, $clear);
    }

    /**
     * Start a closure binding whose lifetime is selected before registration.
     */
    public function factory(string $id, Closure $factory): PendingFactoryBinding
    {
        return parent::factory($id, $factory);
    }

    /**
     * Resolve all entries currently associated with the tag.
     *
     * @return array<string, mixed>
     */
    public function findByTag(string $tag): array
    {
        return parent::findByTag($tag);
    }

    /**
     * Yield deferred resolvers so each tagged entry is constructed only when requested.
     *
     * @return iterable<string, callable(): mixed>
     */
    public function findByTagLazy(string $tag): iterable
    {
        return parent::findByTagLazy($tag);
    }

    /**
     * Create an isolated configuration-capable runtime, optionally locked against mutation.
     */
    public function forkConfigurationRuntime(bool $locked = true): self
    {
        $runtime = new self($this->instanceAlias);
        $this->copyConfigurationInto($runtime, $locked);

        return $runtime;
    }

    /**
     * Resolve native declarations while retaining legacy discovery for mutable configuration
     * callers.
     */
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

    /**
     * Materialize the deferred invoker once and reject invalid resolver implementations.
     */
    public function getCurrentResolver(): \Infocyph\InterMix\DI\Invoker\CompiledCall|InjectedCall|GenericCall
    {
        return parent::getCurrentResolver();
    }

    /**
     * Expose internal configuration and resolution state to trusted library collaborators.
     */
    public function getRepository(): Repository
    {
        return parent::getRepository();
    }

    /**
     * Resolve an entry using legacy post-construction method-return semantics.
     */
    public function getReturn(string $id): mixed
    {
        return $this->invocation()->getReturnLegacy($id);
    }

    /**
     * Include legacy discoverable classes, callable resources and environment-bound interfaces.
     */
    public function has(string $id): bool
    {
        return $this->invocation()->hasLegacy($id);
    }

    /**
     * Reuse the container-owned invocation manager and its lifetime guards.
     */
    public function invocation(): \Infocyph\InterMix\DI\Managers\InvocationManager
    {
        return parent::invocation();
    }

    /**
     * Check whether an identifier has completed resolution, without resolving it.
     */
    public function isResolved(string $id): bool
    {
        return parent::isResolved($id);
    }

    /**
     * Run cleanup for the current scope and restore its parent.
     */
    public function leaveScope(): self
    {
        parent::leaveScope();

        return $this;
    }

    /**
     * Prevent subsequent mutations of this container's configuration.
     */
    public function lock(): self
    {
        parent::lock();

        return $this;
    }

    /**
     * Register a callback that may supply definitions when legacy resolution misses.
     */
    public function onMissing(callable $callback): self
    {
        parent::onMissing($callback);

        return $this;
    }

    /**
     * Register a callback invoked after a service finishes construction.
     */
    public function onResolved(string $id, callable $callback): self
    {
        parent::onResolved($id, $callback);

        return $this;
    }

    /**
     * Register a callback invoked before a service begins construction.
     */
    public function onResolving(string $id, callable $callback): self
    {
        parent::onResolving($id, $callback);

        return $this;
    }

    /**
     * Register cleanup work for the named scope.
     */
    public function onScopeLeave(string $scope, callable $callback): self
    {
        parent::onScopeLeave($scope, $callback);

        return $this;
    }

    /**
     * Reuse the options manager; validate the configuration host only on first use.
     */
    public function options(): OptionsManager
    {
        return parent::options();
    }

    /**
     * Reuse the registration manager; validate the configuration host only on first use.
     */
    public function registration(): RegistrationManager
    {
        return parent::registration();
    }

    /**
     * Resolve a legacy target immediately with the supplied construction and method options.
     *
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

    /**
     * Register a service reused within each scope; omitted definitions use the identifier.
     *
     * @param array<int, string> $tags
     */
    public function scoped(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::scoped($id, $definition, $tags);

        return $this;
    }

    /**
     * Select environment overrides and invalidate resolution state affected by the change.
     */
    public function setEnvironment(string $env): self
    {
        parent::setEnvironment($env);

        return $this;
    }

    /**
     * Switch the injection strategy and invalidate cached resolution configuration.
     *
     * @param class-string<InjectedCall|GenericCall> $resolverClass
     */
    public function setResolverClass(string $resolverClass): void
    {
        parent::setResolverClass($resolverClass);
    }

    /**
     * Register a service reused by the container; omitted definitions use the identifier.
     *
     * @param array<int, string> $tags
     */
    public function singleton(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::singleton($id, $definition, $tags);

        return $this;
    }

    /**
     * Resolve only entries admitted by the native declaration contract.
     */
    public function strictGet(string $id): mixed
    {
        return parent::get($id);
    }

    /**
     * Check native declaration presence without legacy class discovery.
     */
    public function strictHas(string $id): bool
    {
        return parent::has($id);
    }

    /**
     * Register a service rebuilt for each resolution; omitted definitions use the identifier.
     *
     * @param array<int, string> $tags
     */
    public function transient(string $id, mixed $definition = null, array $tags = []): self
    {
        parent::transient($id, $definition, $tags);

        return $this;
    }

    /**
     * Remove a definition and invalidate the resolution state associated with it.
     */
    public function unbind(string $id): self
    {
        parent::unbind($id);

        return $this;
    }

    /**
     * Validate a legacy resolver against this configuration before activating it.
     */
    public function useCompiled(string $path): self
    {
        parent::useCompiled($path);

        return $this;
    }

    /**
     * Activate a legacy resolver using the caller's expected artifact fingerprint.
     */
    public function usePrevalidated(string $path, string $fingerprint): self
    {
        parent::usePrevalidated($path, $fingerprint);

        return $this;
    }

    /**
     * Report invalid targets, optionally resolve factories, and throw when strict validation fails.
     *
     * @return array<int, string>
     */
    public function validate(bool $strict = false, bool $resolveFactories = false): array
    {
        return parent::validate($strict, $resolveFactories);
    }

    /**
     * Store a singleton binding using the legacy definition interpretation rules.
     */
    public function value(string $id, mixed $value): self
    {
        parent::value($id, $value);

        return $this;
    }

    /**
     * Begin a dependency override restricted to the specified consuming class.
     */
    public function when(string $consumer): ContextualBindingBuilder
    {
        return parent::when($consumer);
    }

    /**
     * Run legacy configuration work in a scope and release its execution state afterward.
     *
     * @param array<string, mixed> $instances
     */
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
