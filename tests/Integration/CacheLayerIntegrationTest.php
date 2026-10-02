<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class IntegrationCacheItem implements CacheItemInterface
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

final class IntegrationCachePool implements CacheItemPoolInterface
{
    public int $bulkReads = 0;

    public int $commits = 0;

    /** @var array<string, mixed> */
    public array $deferred = [];

    public bool $failReads = false;

    public bool $failWrites = false;

    public int $singleReads = 0;

    /** @var array<string, mixed> */
    public array $store = [];

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function commit(): bool
    {
        ++$this->commits;
        if ($this->failWrites) {
            return false;
        }
        foreach ($this->deferred as $key => $value) {
            $this->store[$key] = $value;
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }

        return true;
    }

    public function getItem(string $key): CacheItemInterface
    {
        ++$this->singleReads;
        if ($this->failReads) {
            throw new RuntimeException('cache read failed');
        }

        return new IntegrationCacheItem($key, $this->store[$key] ?? null, array_key_exists($key, $this->store));
    }

    public function getItems(array $keys = []): iterable
    {
        ++$this->bulkReads;
        if ($this->failReads) {
            throw new RuntimeException('cache bulk read failed');
        }

        foreach ($keys as $key) {
            yield $key => new IntegrationCacheItem(
                $key,
                $this->store[$key] ?? null,
                array_key_exists($key, $this->store),
            );
        }
    }

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($this->failWrites) {
            return false;
        }
        $this->store[$item->getKey()] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($this->failWrites) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item->get();

        return true;
    }
}

function cacheLayerContainer(string $alias): Container
{
    return new ConfigurationContainer('cachelayer.' . $alias);
}

it('persists only safe singleton values through CacheLayer memory', function () {
    $cache = Cache::memory('intermix.memory');
    $alias = uniqid('memory.', true);
    $misses = 0;

    $first = cacheLayerContainer($alias);
    $first->definitions()->enableDefinitionCache($cache, 'memory-generation');
    $first->bind('scalar', static fn(): int => 42);
    $first->bind('null', static fn(): null => null);
    $first->bind('nested', static fn(): array => ['safe' => [1, null, 'two']]);
    $first->bind('object', static fn(): object => new stdClass());
    $first->bind('closure', static fn(): Closure => static fn(): int => 1);
    $first->bind('resource', static fn() => fopen('php://memory', 'rb'));

    expect($first->get('scalar'))->toBe(42)
        ->and($first->get('null'))->toBeNull()
        ->and($first->get('nested'))->toBe(['safe' => [1, null, 'two']]);
    $object = $first->get('object');
    $closure = $first->get('closure');
    $resource = $first->get('resource');

    expect($object)->toBeInstanceOf(stdClass::class)
        ->and($closure)->toBeInstanceOf(Closure::class)
        ->and(is_resource($resource))->toBeTrue();

    $keys = [];
    foreach (['scalar', 'null', 'nested', 'object', 'closure', 'resource'] as $id) {
        $keys[$id] = $first->getRepository()->makeDefinitionCacheKey($id);
    }
    expect($cache->hasItem($keys['scalar']))->toBeTrue()
        ->and($cache->hasItem($keys['null']))->toBeTrue()
        ->and($cache->hasItem($keys['nested']))->toBeTrue()
        ->and($cache->hasItem($keys['object']))->toBeFalse()
        ->and($cache->hasItem($keys['closure']))->toBeFalse()
        ->and($cache->hasItem($keys['resource']))->toBeFalse();

    if (is_resource($resource)) {
        fclose($resource);
    }

    $second = cacheLayerContainer($alias);
    $second->definitions()->enableDefinitionCache($cache, 'memory-generation');
    $second->bind('scalar', function () use (&$misses): int {
        ++$misses;

        return 99;
    });
    $second->bind('null', static fn(): string => 'not-null');
    $second->bind('nested', static fn(): array => ['not' => 'used']);
    $second->bind('object', static fn(): object => new stdClass());
    $second->bind('closure', static fn(): Closure => static fn(): int => 2);
    $second->bind('resource', static fn() => fopen('php://memory', 'rb'));

    expect($second->get('scalar'))->toBe(42)
        ->and($second->get('null'))->toBeNull()
        ->and($second->get('nested'))->toBe(['safe' => [1, null, 'two']])
        ->and($misses)->toBe(0);
});

it('isolates CacheLayer entries by InterMix generation', function () {
    $cache = Cache::memory('intermix.generation');
    $alias = uniqid('generation.', true);

    $first = cacheLayerContainer($alias);
    $first->definitions()->enableDefinitionCache($cache, 'release-a');
    $first->bind('value', static fn(): string => 'a');
    expect($first->get('value'))->toBe('a');

    $second = cacheLayerContainer($alias);
    $second->definitions()->enableDefinitionCache($cache, 'release-b');
    $second->bind('value', static fn(): string => 'b');

    expect($second->get('value'))->toBe('b');
});

it('warms CacheLayer memory in bulk and reports hits and skips', function () {
    $cache = Cache::memory('intermix.warmup');
    $alias = uniqid('warmup.', true);

    $first = cacheLayerContainer($alias);
    $first->definitions()->enableDefinitionCache($cache, 'warmup-generation');
    $first->bind('safe', static fn(): array => ['ready' => true]);
    $first->bind('object', static fn(): object => new stdClass());
    $first->bind('transient', static fn(): int => 1, LifetimeEnum::Transient);
    $firstReport = $first->definitions()->warmDefinitionCache();

    expect($firstReport['written'])->toBe(1)
        ->and($firstReport['hits'])->toBe(0)
        ->and($firstReport['skipped'])->toBeGreaterThanOrEqual(3)
        ->and($firstReport['failed'])->toBe(0);

    $second = cacheLayerContainer($alias);
    $second->definitions()->enableDefinitionCache($cache, 'warmup-generation');
    $second->bind('safe', static fn(): array => ['unused' => true]);
    $second->bind('object', static fn(): object => new stdClass());
    $second->bind('transient', static fn(): int => 1, LifetimeEnum::Transient);
    $secondReport = $second->definitions()->warmDefinitionCache();

    expect($secondReport['hits'])->toBe(1)
        ->and($secondReport['written'])->toBe(0)
        ->and($secondReport['failed'])->toBe(0);
});

it('integrates with CacheLayer APCu when available', function () {
    if (!extension_loaded('apcu') || !apcu_enabled()) {
        expect(extension_loaded('apcu') && apcu_enabled())->toBeFalse();

        return;
    }

    $cache = Cache::apcu('intermix.apcu.' . substr(hash('xxh128', uniqid('', true)), 0, 12));
    $container = cacheLayerContainer(uniqid('apcu.', true));
    $container->definitions()->enableDefinitionCache($cache);
    $container->bind('value', static fn(): int => 42);

    expect($container->get('value'))->toBe(42);
});

it('persists definitions between containers through CacheLayer SQLite', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(extension_loaded('pdo_sqlite'))->toBeFalse();

        return;
    }

    $file = sys_get_temp_dir() . '/intermix-cachelayer-' . bin2hex(random_bytes(8)) . '.sqlite';
    $alias = uniqid('sqlite.', true);

    try {
        $firstCache = Cache::sqlite('intermix.sqlite', $file);
        $first = cacheLayerContainer($alias);
        $first->definitions()->enableDefinitionCache($firstCache, 'sqlite-generation');
        $first->bind('persistent', static fn(): array => ['from' => 'first']);
        expect($first->get('persistent'))->toBe(['from' => 'first']);

        $secondCache = Cache::sqlite('intermix.sqlite', $file);
        $second = cacheLayerContainer($alias);
        $second->definitions()->enableDefinitionCache($secondCache, 'sqlite-generation');
        $second->bind('persistent', static fn(): array => ['from' => 'second']);

        expect($second->get('persistent'))->toBe(['from' => 'first']);
    } finally {
        foreach ([$file, $file . '-shm', $file . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

it('accepts a CacheLayer tiered pool without backend assumptions', function () {
    $cache = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'intermix.tier.l1'],
        ['driver' => 'memory', 'namespace' => 'intermix.tier.l2'],
    ]);
    $alias = uniqid('tiered.', true);

    $first = cacheLayerContainer($alias);
    $first->definitions()->enableDefinitionCache($cache, 'tiered-generation');
    $first->bind('tiered', static fn(): string => 'cached');
    expect($first->get('tiered'))->toBe('cached');

    $second = cacheLayerContainer($alias);
    $second->definitions()->enableDefinitionCache($cache, 'tiered-generation');
    $second->bind('tiered', static fn(): string => 'miss');

    expect($second->get('tiered'))->toBe('cached');
});

it('uses the PSR-6 bulk contract for warmup', function () {
    $pool = new IntegrationCachePool();
    $container = cacheLayerContainer(uniqid('bulk.', true));
    $container->definitions()->enableDefinitionCache($pool);
    $container->bind('safe', static fn(): int => 42);
    $container->bind('transient', static fn(): int => 7, LifetimeEnum::Transient);

    $report = $container->definitions()->warmDefinitionCache();

    expect($pool->bulkReads)->toBe(1)
        ->and($pool->singleReads)->toBe(0)
        ->and($pool->commits)->toBe(1)
        ->and($report['written'])->toBe(1)
        ->and($report['skipped'])->toBeGreaterThanOrEqual(2)
        ->and($report['failed'])->toBe(0);
});

it('fails open by default and can surface PSR-6 failures', function () {
    $failOpenPool = new IntegrationCachePool();
    $failOpenPool->failReads = true;
    $failOpen = cacheLayerContainer(uniqid('fail-open.', true));
    $failOpen->definitions()->enableDefinitionCache($failOpenPool);
    $failOpen->bind('answer', static fn(): int => 42);

    expect($failOpen->get('answer'))->toBe(42);

    $strictPool = new IntegrationCachePool();
    $strictPool->failReads = true;
    $strict = cacheLayerContainer(uniqid('strict.', true));
    $strict->definitions()->enableDefinitionCache($strictPool, failOpen: false);
    $strict->bind('answer', static fn(): int => 42);

    expect(fn() => $strict->get('answer'))->toThrow(ContainerException::class);
});

it('keeps resolved definitions available when PSR-6 writes fail open', function () {
    $failOpenPool = new IntegrationCachePool();
    $failOpenPool->failWrites = true;
    $failOpen = cacheLayerContainer(uniqid('write-fail-open.', true));
    $failOpen->definitions()->enableDefinitionCache($failOpenPool);
    $failOpen->bind('answer', static fn(): int => 42);

    expect($failOpen->get('answer'))->toBe(42);

    $strictPool = new IntegrationCachePool();
    $strictPool->failWrites = true;
    $strict = cacheLayerContainer(uniqid('write-strict.', true));
    $strict->definitions()->enableDefinitionCache($strictPool, failOpen: false);
    $strict->bind('answer', static fn(): int => 42);

    expect(fn() => $strict->get('answer'))->toThrow(ContainerException::class);
});

it('reports fail-open warmup failures and surfaces strict failures', function () {
    $failOpenPool = new IntegrationCachePool();
    $failOpenPool->failReads = true;
    $failOpen = cacheLayerContainer(uniqid('warm-fail-open.', true));
    $failOpen->definitions()->enableDefinitionCache($failOpenPool);
    $failOpen->bind('answer', static fn(): int => 42);
    $report = $failOpen->definitions()->warmDefinitionCache();

    expect($report['failed'])->toBeGreaterThanOrEqual(1)
        ->and($failOpen->get('answer'))->toBe(42);

    $strictPool = new IntegrationCachePool();
    $strictPool->failReads = true;
    $strict = cacheLayerContainer(uniqid('warm-strict.', true));
    $strict->definitions()->enableDefinitionCache($strictPool, failOpen: false);
    $strict->bind('answer', static fn(): int => 42);

    expect(fn() => $strict->definitions()->warmDefinitionCache())
        ->toThrow(RuntimeException::class, 'cache bulk read failed');
});

it('keeps cache configuration idempotent and cache keys opaque', function () {
    $pool = new IntegrationCachePool();
    $replacement = new IntegrationCachePool();
    $container = new ConfigurationContainer('private.tenant.path');
    $container->setEnvironment('production-secret');
    $container->bind('Sensitive\\Service\\Name', static fn(): string => 'resolved');
    $container->definitions()->enableDefinitionCache($pool, 'release-secret');

    $repository = $container->getRepository();
    $key = $repository->makeDefinitionCacheKey('Sensitive\\Service\\Name');
    $container->definitions()->enableDefinitionCache($pool, 'release-secret');

    expect($repository->makeDefinitionCacheKey('Sensitive\\Service\\Name'))->toBe($key)
        ->and(strlen($key))->toBeLessThanOrEqual(64)
        ->and($key)->toMatch('/^imx\.[a-f0-9]{16}\.[a-f0-9]{16}\.[a-f0-9]{16}$/')
        ->and($key)->not->toContain('Sensitive', 'production', 'tenant', 'release');

    expect($container->get('Sensitive\\Service\\Name'))->toBe('resolved');
    $container->definitions()->enableDefinitionCache($replacement, 'release-secret', false);
    expect($container->get('Sensitive\\Service\\Name'))->toBe('resolved')
        ->and($replacement->singleReads)->toBe(0);

    $container->definitions()->enableDefinitionCache($replacement, 'next-release', false);
    expect($repository->makeDefinitionCacheKey('Sensitive\\Service\\Name'))->not->toBe($key);
});


it('uses CacheLayer 4 memory with explicit 11.0 cache identity and eligibility', function (): void {
    $cache = Cache::memory('intermix.p5.memory.' . bin2hex(random_bytes(4)));
    $runs = 0;

    $first = ContainerBuilder::create(uniqid('p5_memory_first_', true))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory('value', static function () use (&$runs): array {
            ++$runs;

            return ['cached' => true];
        })
        ->cacheDefinition('value')
        ->build();

    expect($first->get('value'))->toBe(['cached' => true])
        ->and($runs)->toBe(1);

    $second = ContainerBuilder::create(uniqid('p5_memory_second_', true))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory('value', static function () use (&$runs): array {
            ++$runs;

            return ['cached' => false];
        })
        ->cacheDefinition('value')
        ->build();

    expect($second->get('value'))->toBe(['cached' => true])
        ->and($runs)->toBe(1)
        ->and(ContainerAccess::repository($second)->makeDefinitionCacheKey('value'))
        ->toStartWith('imx11.');
});

it('treats cached null as a real CacheLayer 4 hit', function (): void {
    $cache = Cache::memory('intermix.p5.null.' . bin2hex(random_bytes(4)));

    $first = ContainerBuilder::create(uniqid('p5_null_first_', true))
        ->definitionCache($cache, 'application-null', 'release-1')
        ->factory('nullable', static fn(): null => null)
        ->cacheDefinition('nullable')
        ->build();

    expect($first->get('nullable'))->toBeNull();

    $second = ContainerBuilder::create(uniqid('p5_null_second_', true))
        ->definitionCache($cache, 'application-null', 'release-1')
        ->factory('nullable', static fn(): string => 'miss')
        ->cacheDefinition('nullable')
        ->build();

    expect($second->get('nullable'))->toBeNull();
});

it('separates CacheLayer 4 values by explicit generation', function (): void {
    $cache = Cache::memory('intermix.p5.generation.' . bin2hex(random_bytes(4)));

    $first = ContainerBuilder::create(uniqid('p5_generation_first_', true))
        ->definitionCache($cache, 'application-generation', 'release-1')
        ->factory('value', static fn(): string => 'first')
        ->cacheDefinition('value')
        ->build();
    expect($first->get('value'))->toBe('first');

    $second = ContainerBuilder::create(uniqid('p5_generation_second_', true))
        ->definitionCache($cache, 'application-generation', 'release-2')
        ->factory('value', static fn(): string => 'second')
        ->cacheDefinition('value')
        ->build();

    expect($second->get('value'))->toBe('second');
});

it('rejects malformed CacheLayer 4 hits and resolves a safe value instead', function (): void {
    $cache = Cache::memory('intermix.p5.malformed.' . bin2hex(random_bytes(4)));
    $builder = ContainerBuilder::create(uniqid('p5_malformed_', true))
        ->definitionCache($cache, 'application-malformed', 'release-1')
        ->factory('value', static fn(): int => 42)
        ->cacheDefinition('value');

    $runtime = $builder->build();
    $key = ContainerAccess::repository($runtime)->makeDefinitionCacheKey('value');
    $item = $cache->getItem($key);
    $item->set(new stdClass());
    $cache->save($item);

    expect($runtime->get('value'))->toBe(42);
});

it('warms CacheLayer 4 through PSR-6 deferred writes and commit', function (): void {
    $cache = Cache::memory('intermix.p5.warm.' . bin2hex(random_bytes(4)));
    $builder = ContainerBuilder::create(uniqid('p5_warm_', true))
        ->definitionCache($cache, 'application-warm', 'release-1')
        ->factory('value', static fn(): array => ['warm' => true])
        ->cacheDefinition('value');

    $report = $builder->warmDefinitionCache();

    expect($report)->toMatchArray([
        'hits' => 0,
        'written' => 1,
        'failed' => 0,
    ])
        ->and($builder->build()->get('value'))->toBe(['warm' => true]);
});

it('uses CacheLayer 4 tiered storage through the PSR-6 boundary', function (): void {
    $cache = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'intermix.p5.tier.l1.' . bin2hex(random_bytes(3))],
        ['driver' => 'memory', 'namespace' => 'intermix.p5.tier.l2.' . bin2hex(random_bytes(3))],
    ]);

    $first = ContainerBuilder::create(uniqid('p5_tier_first_', true))
        ->definitionCache($cache, 'application-tier', 'release-1')
        ->factory('value', static fn(): string => 'tiered')
        ->cacheDefinition('value')
        ->build();
    expect($first->get('value'))->toBe('tiered');

    $second = ContainerBuilder::create(uniqid('p5_tier_second_', true))
        ->definitionCache($cache, 'application-tier', 'release-1')
        ->factory('value', static fn(): string => 'miss')
        ->cacheDefinition('value')
        ->build();

    expect($second->get('value'))->toBe('tiered');
});

it('uses CacheLayer 4 SQLite through the explicit builder cache contract', function (): void {
    if (!extension_loaded('pdo_sqlite')) {
        expect(extension_loaded('pdo_sqlite'))->toBeFalse();

        return;
    }

    $file = sys_get_temp_dir() . '/intermix-p5-' . bin2hex(random_bytes(8)) . '.sqlite';
    try {
        $first = ContainerBuilder::create(uniqid('p5_sqlite_first_', true))
            ->definitionCache(
                Cache::sqlite('intermix.p5.sqlite', $file),
                'application-sqlite',
                'release-1',
            )
            ->factory('value', static fn(): string => 'sqlite')
            ->cacheDefinition('value')
            ->build();
        expect($first->get('value'))->toBe('sqlite');

        $second = ContainerBuilder::create(uniqid('p5_sqlite_second_', true))
            ->definitionCache(
                Cache::sqlite('intermix.p5.sqlite', $file),
                'application-sqlite',
                'release-1',
            )
            ->factory('value', static fn(): string => 'miss')
            ->cacheDefinition('value')
            ->build();

        expect($second->get('value'))->toBe('sqlite');
    } finally {
        foreach ([$file, $file . '-shm', $file . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});

it('uses CacheLayer 4 APCu when the CLI backend is enabled', function (): void {
    if (!extension_loaded('apcu') || !apcu_enabled()) {
        expect(extension_loaded('apcu') && apcu_enabled())->toBeFalse();

        return;
    }

    $cache = Cache::apcu('intermix.p5.apcu.' . bin2hex(random_bytes(4)));
    $runtime = ContainerBuilder::create(uniqid('p5_apcu_', true))
        ->definitionCache($cache, 'application-apcu', 'release-1')
        ->factory('value', static fn(): int => 42)
        ->cacheDefinition('value')
        ->build();

    expect($runtime->get('value'))->toBe(42);
});
