<?php

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

interface CacheInterface extends CacheItemPoolInterface, SimpleCacheInterface, ArrayAccess
{
}
