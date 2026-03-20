<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Psr\Cache\CacheItemInterface;

/**
 * Internal contract used by cache items to persist themselves.
 *
 * @internal
 */
interface InternalCachePoolInterface
{
    public function internalPersist(CacheItemInterface $item): bool;

    public function internalQueue(CacheItemInterface $item): bool;
}
