<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Marker contract for InterMix definition cache pools.
 *
 * Extends PHP-FIG PSR-6 so external cache packages can be injected directly.
 */
interface DefinitionCachePoolInterface extends CacheItemPoolInterface {}
