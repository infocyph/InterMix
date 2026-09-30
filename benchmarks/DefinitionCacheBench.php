<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use DateInterval;
use DateTimeInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[Revs(1)]
#[Iterations(3)]
#[Warmup(1)]
final class DefinitionCacheBench
{
    private ?Cache $apcu = null;

    private Cache $cacheLayerMemory;

    private DefinitionCacheBenchPool $psrMemory;

    public function setUp(): void
    {
        $this->psrMemory = new DefinitionCacheBenchPool();
        $this->cacheLayerMemory = Cache::memory('intermix.benchmark');
        $this->seedHit($this->psrMemory, '__definition_cache_psr__');
        $this->seedHit($this->cacheLayerMemory, '__definition_cache_cachelayer__');

        $this->apcu = extension_loaded('apcu') && apcu_enabled()
            ? Cache::apcu('intermix.benchmark.' . substr(hash('xxh128', uniqid('', true)), 0, 12))
            : null;
        if ($this->apcu instanceof Cache) {
            $this->seedHit($this->apcu, '__definition_cache_apcu__');
        }
    }

    public function benchBulkWarmup10(): void
    {
        $this->warm(10, true);
    }

    public function benchBulkWarmup100(): void
    {
        $this->warm(100, true);
    }

    public function benchBulkWarmup1000(): void
    {
        $this->warm(1000, true);
    }

    #[BeforeMethods('setUp')]
    public function benchCacheLayerApcuHit(): void
    {
        if (!$this->apcu instanceof Cache) {
            return;
        }

        $this->resolveHit($this->apcu, '__definition_cache_apcu__');
    }

    #[BeforeMethods('setUp')]
    public function benchCacheLayerMemoryHit(): void
    {
        $this->resolveHit($this->cacheLayerMemory, '__definition_cache_cachelayer__');
    }

    public function benchGenerationRotation(): void
    {
        $pool = Cache::memory('intermix.rotation');
        $this->warmupBuilder(100, $pool, 'release-1')->warmDefinitionCache();
        $this->warmupBuilder(100, $pool, 'release-2')->warmDefinitionCache();
    }

    #[BeforeMethods('setUp')]
    public function benchPsrMemoryHit(): void
    {
        $this->resolveHit($this->psrMemory, '__definition_cache_psr__');
    }

    public function benchSequentialWarmup100(): void
    {
        $this->warm(100, false);
    }

    public function benchUncachedSingletonScalarResolve(): void
    {
        ContainerBuilder::create(uniqid('__definition_cache_uncached__', true))
            ->factory(
                'value',
                FactoryDefinition::staticFactory(DefinitionCacheBenchFactory::class, 'value'),
            )
            ->build()
            ->get('value');
    }

    private function resolveHit(CacheItemPoolInterface $pool, string $alias): void
    {
        ContainerBuilder::create($alias)
            ->definitionCache($pool, 'benchmark', $alias)
            ->factory(
                'value',
                FactoryDefinition::staticFactory(DefinitionCacheBenchFactory::class, 'value'),
            )
            ->cacheDefinition('value')
            ->build()
            ->get('value');
    }

    private function seedHit(CacheItemPoolInterface $pool, string $alias): void
    {
        $this->resolveHit($pool, $alias);
    }

    private function warm(int $count, bool $bulk): void
    {
        $builder = $this->warmupBuilder(
            $count,
            Cache::memory('intermix.warm.' . $count),
            'release-1',
        );
        if ($bulk) {
            $builder->warmDefinitionCache();

            return;
        }

        $container = $builder->build();
        for ($index = 0; $index < $count; ++$index) {
            $container->get('value.' . $index);
        }
    }

    private function warmupBuilder(
        int $count,
        CacheItemPoolInterface $pool,
        string $generation,
    ): ContainerBuilder {
        $builder = ContainerBuilder::create(uniqid('__definition_cache_warm__', true))
            ->definitionCache($pool, 'benchmark', $generation);
        for ($index = 0; $index < $count; ++$index) {
            $id = 'value.' . $index;
            $builder
                ->factory(
                    $id,
                    FactoryDefinition::staticFactory(DefinitionCacheBenchFactory::class, 'value'),
                )
                ->cacheDefinition($id);
        }

        return $builder;
    }
}

final class DefinitionCacheBenchFactory
{
    public static function value(): int
    {
        return 42;
    }
}

final class DefinitionCacheBenchItem implements CacheItemInterface
{
    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {}

    public function expiresAfter(DateInterval|int|null $time): static
    {
        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}

final class DefinitionCacheBenchPool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    private array $deferred = [];

    /** @var array<string, mixed> */
    private array $values = [];

    public function clear(): bool
    {
        $this->values = [];
        $this->deferred = [];

        return true;
    }

    public function commit(): bool
    {
        foreach ($this->deferred as $key => $value) {
            $this->values[$key] = $value;
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function getItem(string $key): CacheItemInterface
    {
        return new DefinitionCacheBenchItem(
            $key,
            $this->values[$key] ?? null,
            array_key_exists($key, $this->values),
        );
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->values[$item->getKey()] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item->get();

        return true;
    }
}
