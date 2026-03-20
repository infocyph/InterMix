<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * APCu-based cache adapter implementation.
 *
 * This adapter uses the APCu PHP extension to provide high-performance
 * in-memory caching. It's suitable for production environments where
 * shared memory caching is available and provides fast access to cached data.
 *
 * Note: This adapter requires the APCu extension to be installed and enabled.
 */
class ApcuCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    /**
     * Creates a new APCu cache adapter.
     *
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @throws RuntimeException If the APCu extension is not enabled.
     */
    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = sanitize_cache_ns($namespace);
    }

    public function clear(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        return count($this->listKeys());
    }

    public function deleteItem(string $key): bool
    {
        return apcu_delete($this->map($key));
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->deleteItem($k);
        }
        return $ok;
    }

    public function getItem(string $key): ApcuCacheItem
    {
        $apcuKey = $this->map($key);
        $success = false;
        $raw = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $record = CachePayloadCodec::decode($raw);
            if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                return new ApcuCacheItem(
                    $this,
                    $key,
                    $record['value'],
                    true,
                    CachePayloadCodec::toDateTime($record['expires']),
                );
            }
            apcu_delete($apcuKey);
        }
        return new ApcuCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return apcu_exists($this->map($key));
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = array_map($this->map(...), $keys);
        $raw = apcu_fetch($prefixed);

        $items = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
            if (array_key_exists($p, $raw)) {
                $record = CachePayloadCodec::decode((string)$raw[$p]);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    $items[$k] = new ApcuCacheItem(
                        $this,
                        $k,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );
                    continue;
                }
                apcu_delete($p);
            }
            $items[$k] = new ApcuCacheItem($this, $k);
        }
        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            apcu_delete($this->map($item->getKey()));
            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        return apcu_store($this->map($item->getKey()), $blob, $ttl ?? 0);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof ApcuCacheItem;
    }

    private function listKeys(): array
    {
        $iter = new \APCUIterator(
            '/^'.preg_quote($this->ns.':', '/').'/',
            APC_ITER_KEY
        );
        $out = [];
        foreach ($iter as $k => $unused) {
            $out[] = $k;
        }
        return $out;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
