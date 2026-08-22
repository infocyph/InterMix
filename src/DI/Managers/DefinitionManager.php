<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use ArrayAccess;
use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ServiceId;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * @implements ArrayAccess<string, mixed>
 */
class DefinitionManager implements ArrayAccess
{
    use ManagerProxy;

    /**
     * Initialize the definition manager.
     *
     * @param Repository $repository The internal repository holding all definitions.
     * @param Container $container The container instance this manager is bound to.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {}

    /**
     * Adds multiple definitions to the container.
     *
     * This method takes an associative array with definition names as keys and
     * the definitions themselves as values. It then internally calls the
     * {@see bind()} method for each definition.
     *
     * @param array<string, mixed> $definitions The array of definitions.
     *
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): self
    {
        // The repository internally checks for lock
        foreach ($definitions as $id => $definition) {
            $this->bind($id, $definition);
        }

        return $this;
    }

    /**
     * Registers a definition with the container.
     *
     * Registers a definition with the container, which can then be retrieved
     * using the {@see get()} method. The definition can be any type of value,
     * including another definition.
     *
     * @param string $id The identifier of the definition.
     * @param mixed $definition The definition itself.
     * @param LifetimeEnum $lifetime The lifetime of the definition.
     * @param array<int, string> $tags An array of tags to associate with the definition.
     *
     * @throws ContainerException if the container is locked or if the id is the same as the definition.
     */
    public function bind(
        string $id,
        mixed $definition,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): self {
        $id = ServiceId::from($id);
        if (is_string($definition) && $id === $definition && !class_exists($definition)) {
            throw new ContainerException("Scalar/string alias cannot point to itself ($id).");
        }
        $this->repository->setFunctionReference($id, $definition);
        $this->repository->setDefinitionMeta($id, [
            'lifetime' => $lifetime,
            'tags' => $tags,
        ]);

        return $this;
    }

    /**
     * Enable definition caching with an assigned cache pool.
     *
     * The given pool can be any PHP-FIG PSR-6 implementation.
     *
     * @throws ContainerException
     */
    public function enableDefinitionCache(
        CacheItemPoolInterface $pool,
        ?string $generation = null,
        bool $failOpen = true,
    ): self {
        $this->repository->setDefinitionCache($pool, $generation, $failOpen);

        return $this;
    }

    /**
     * Determine whether an ID was explicitly registered as a definition or callable resource.
     *
     * This intentionally excludes autowireable classes, environment interface
     * mappings, and entries that only exist because they were resolved.
     */
    public function has(string $id): bool
    {
        return $this->repository->hasFunctionReference($id)
            || $this->repository->hasClosureResource($id);
    }

    /**
     * Jump to InvocationManager
     */
    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }

    /**
     * Jump to OptionsManager
     */
    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    /**
     * Jump to RegistrationManager
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    /**
     * Override existing definition metadata for a specific environment.
     *
     * @param array<int, string>|null $tags
     *
     * @throws ContainerException
     */
    public function setMetaForEnv(
        string $env,
        string $id,
        ?LifetimeEnum $lifetime = null,
        ?array $tags = null,
    ): self {
        $this->container->options()->setDefinitionMetaForEnv($env, $id, $lifetime, $tags);

        return $this;
    }

    public function unbind(string $id): self
    {
        $this->repository->removeDefinition(ServiceId::from($id));

        return $this;
    }

    /**
     * Warm safe singleton definitions using one PSR-6 bulk read and commit.
     *
     * @return array{hits: int, written: int, skipped: int, failed: int}
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function warmDefinitionCache(bool $rotateGeneration = false): array
    {
        $definitions = $this->repository->getFunctionReference();
        if ($definitions === []) {
            throw new ContainerException('No definitions added.');
        }
        $definitionCache = $this->repository->getDefinitionCache();
        if ($definitionCache === null) {
            throw new ContainerException('No definition cache set.');
        }
        if ($rotateGeneration) {
            $this->repository->rotateDefinitionCacheGeneration();
        }

        $resolver = $this->container->getCurrentResolver();
        if ($resolver instanceof GenericCall) {
            throw new ContainerException('Definition caching requires injection-enabled resolver.');
        }

        $report = ['hits' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];
        $keys = $this->warmupKeys($definitions, $report);

        if ($keys === []) {
            return $report;
        }

        $items = $this->warmupItems($definitionCache, array_values($keys));
        if ($items === null) {
            $report['failed'] += count($keys);
            $this->resolveWarmupDefinitions($resolver, array_keys($keys));

            return $report;
        }

        $deferred = 0;
        foreach ($keys as $id => $key) {
            $outcome = $this->warmDefinition($resolver, $definitionCache, $id, $items[$key] ?? null);
            ++$report[$outcome];
            if ($outcome === 'written') {
                ++$deferred;
            }
        }

        if ($deferred > 0 && !$this->commitDefinitionCache($definitionCache)) {
            $report['written'] -= $deferred;
            $report['failed'] += $deferred;
        }

        return $report;
    }

    private function canResolveToPersistableValue(mixed $definition): bool
    {
        return !is_resource($definition)
            && (!is_object($definition)
                || $definition instanceof Closure
                || $definition instanceof DirectFactory
                || $definition instanceof FactoryDefinition);
    }

    private function commitDefinitionCache(CacheItemPoolInterface $cache): bool
    {
        try {
            if (!$cache->commit()) {
                throw new ContainerException('Definition cache commit failed.');
            }

            return true;
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);

            return false;
        }
    }

    private function ignoreDefinitionCacheFailure(Throwable $failure): void
    {
        if (!$this->repository->isDefinitionCacheFailOpen()) {
            throw $failure;
        }
    }

    /** @param list<string> $ids */
    private function resolveWarmupDefinitions(CompiledCall|InjectedCall $resolver, array $ids): void
    {
        foreach ($ids as $id) {
            $resolver->resolveDefinitionForWarmup($id);
        }
    }

    /**
     * @return string Warmup report field to increment.
     * @phpstan-return 'hits'|'written'|'skipped'|'failed'
     */
    private function warmDefinition(
        CompiledCall|InjectedCall $resolver,
        CacheItemPoolInterface $cache,
        string $id,
        mixed $item,
    ): string {
        if (!$item instanceof CacheItemInterface) {
            $resolver->resolveDefinitionForWarmup($id);

            return 'failed';
        }

        try {
            if ($item->isHit() && $this->repository->shouldPersistDefinitionValue($item->get())) {
                return 'hits';
            }
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);
            $resolver->resolveDefinitionForWarmup($id);

            return 'failed';
        }

        $value = $resolver->resolveDefinitionForWarmup($id);
        if (!$this->repository->shouldPersistDefinitionValue($value)) {
            return 'skipped';
        }

        try {
            $item->set($value);
            if (!$cache->saveDeferred($item)) {
                throw new ContainerException('Definition cache deferred write failed.');
            }

            return 'written';
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);

            return 'failed';
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItemInterface>|null
     */
    private function warmupItems(CacheItemPoolInterface $cache, array $keys): ?array
    {
        try {
            $items = [];
            foreach ($cache->getItems($keys) as $item) {
                if ($item instanceof CacheItemInterface) {
                    $items[$item->getKey()] = $item;
                }
            }

            return $items;
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $definitions
     * @param array{hits: int, written: int, skipped: int, failed: int} $report
     * @return array<string, string>
     */
    private function warmupKeys(array $definitions, array &$report): array
    {
        $keys = [];
        foreach ($definitions as $id => $definition) {
            if ($this->repository->getDefinitionLifetime($id) !== LifetimeEnum::Singleton
                || !$this->canResolveToPersistableValue($definition)
            ) {
                ++$report['skipped'];

                continue;
            }
            $keys[$id] = $this->repository->makeDefinitionCacheKey($id);
        }

        return $keys;
    }
}
