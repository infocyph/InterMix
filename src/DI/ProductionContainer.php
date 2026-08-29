<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Internal\ScopeState;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Container\ContainerInterface;
use Throwable;

abstract class ProductionContainer implements ContainerInterface
{
    protected ScopeState $scope;

    private ?Container $fallback;

    public function __construct(?Container $fallback = null)
    {
        $this->scope = new ScopeState('root');
        $this->fallback = $fallback;
        if ($fallback instanceof Container) {
            $this->installFallbackBridges($fallback);
        }
    }

    abstract protected function slotFor(string $id): ?int;

    /** @internal */
    final public function attachFallback(Container $fallback): void
    {
        $this->installFallbackBridges($fallback);
        $this->fallback = $fallback;
        $this->synchronizeFallbackScopes($fallback);
    }

    /** @throws ContainerException|\ReflectionException|\Psr\Cache\InvalidArgumentException */
    final public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        if (is_string($classOrClosure) && $this->isCompiledDefinition($classOrClosure)) {
            return $this->callCompiledDefinition($classOrClosure, $method);
        }

        return $this->dynamic()->call($classOrClosure, $method);
    }

    /** @param array<string, mixed> $instances */
    final public function enterScope(string $scope, array $instances = []): static
    {
        if ($scope === 'root' || $this->scope->contains($scope)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $seeds = [];
        foreach ($instances as $id => $value) {
            $slot = $this->slotFor($id);
            if ($slot !== null) {
                $seeds[$slot] = $value;
            }
        }

        $this->scope = new ScopeState($scope, $this->scope, $seeds, $instances);
        $this->fallback?->enterScope($scope, $instances);

        return $this;
    }

    /** @return array<string, mixed> */
    final public function findByTag(string $tag): array
    {
        $matches = [];
        foreach ($this->taggedIds($tag) as $id) {
            $matches[$id] = $this->get($id);
        }

        if ($this->fallback instanceof Container) {
            foreach ($this->fallback->findByTag($tag) as $id => $value) {
                $matches[$id] ??= $value;
            }
        }

        return $matches;
    }

    /** @return iterable<string, callable(): mixed> */
    final public function findByTagLazy(string $tag): iterable
    {
        foreach ($this->taggedIds($tag) as $id) {
            yield $id => fn() => $this->get($id);
        }

        if (!$this->fallback instanceof Container) {
            return;
        }

        $compiled = array_fill_keys($this->taggedIds($tag), true);
        foreach ($this->fallback->findByTagLazy($tag) as $id => $resolver) {
            if (!isset($compiled[$id])) {
                yield $id => $resolver;
            }
        }
    }

    /** @throws ContainerException|\ReflectionException|\Psr\Cache\InvalidArgumentException */
    final public function getReturn(string $id): mixed
    {
        if ($this->isCompiledDefinition($id)) {
            return $this->get($id);
        }

        return $this->dynamic()->getReturn($id);
    }

    final public function leaveScope(): static
    {
        $this->fallback?->leaveScope();
        $parent = $this->scope->parent;
        $this->scope = $parent ?? new ScopeState('root');

        return $this;
    }

    /** @throws ContainerException|\ReflectionException */
    final public function make(string $class, string|bool $method = false): mixed
    {
        if ($method === false) {
            $fresh = $this->freshCompiled($class);
            if ($fresh !== null) {
                return $fresh;
            }
        }

        return $this->dynamic()->make($class, $method);
    }

    /**
     * @param string|array{0:string,1:string}|Closure|callable|null $spec
     * @param array<int|string, mixed> $parameters
     */
    final public function resolveNow(
        string|Closure|callable|array|null $spec,
        array $parameters = [],
    ): mixed {
        if ($spec === null) {
            return $this;
        }

        if ($parameters === [] && is_string($spec)) {
            $fresh = $this->freshCompiled($spec);
            if ($fresh !== null) {
                return $fresh;
            }
        }

        return $this->dynamic()->resolveNow($spec, $parameters);
    }

    /** @return iterable<string, callable(): mixed> */
    final public function tagged(string $tag): iterable
    {
        return $this->findByTagLazy($tag);
    }

    /** @param array<string, mixed> $instances */
    final public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);

        try {
            return $callback($this);
        } finally {
            $this->leaveScope();
        }
    }

    /** @return array<int, string> */
    protected function compiledIds(): array
    {
        return [];
    }

    final protected function fallbackGet(string $id): mixed
    {
        return $this->dynamic()->get($id);
    }

    final protected function fallbackHas(string $id): bool
    {
        return $this->dynamic()->has($id);
    }

    protected function freshCompiled(string $class): ?object
    {
        return null;
    }

    protected function isCompiledDefinition(string $id): bool
    {
        return false;
    }

    /** @return array<int, string> */
    protected function taggedIds(string $tag): array
    {
        return match ($tag) {
            default => [],
        };
    }

    private function callCompiledDefinition(string $id, string|bool|null $method): mixed
    {
        $service = $this->get($id);
        if (!is_string($method) || $method === '') {
            return $service;
        }
        if (!is_object($service) || !method_exists($service, $method)) {
            throw new ContainerException("Method {$id}::{$method}() does not exist.");
        }

        return $service->{$method}();
    }

    private function dynamic(): Container
    {
        if ($this->fallback instanceof Container) {
            return $this->fallback;
        }

        $fallback = new Container('intermix.production.dynamic.' . spl_object_id($this));
        $this->installFallbackBridges($fallback);
        $this->synchronizeFallbackScopes($fallback);
        $this->fallback = $fallback;

        return $fallback;
    }

    private function installFallbackBridges(Container $fallback): void
    {
        foreach ($this->compiledIds() as $id) {
            $fallback->bindFactory(
                $id,
                fn(): mixed => $this->get($id),
                LifetimeEnum::Transient,
            );
        }
    }

    private function synchronizeFallbackScopes(Container $fallback): void
    {
        $scopes = [];
        for ($scope = $this->scope; $scope->parent instanceof ScopeState; $scope = $scope->parent) {
            $scopes[] = $scope;
        }

        try {
            foreach (array_reverse($scopes) as $scope) {
                $fallback->enterScope($scope->name, $scope->rawSeeds);
            }
        } catch (Throwable $throwable) {
            throw new ContainerException('Unable to synchronize production fallback scope state.', previous: $throwable);
        }
    }
}
