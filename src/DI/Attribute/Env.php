<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

/**
 * Declarative environment-specific binding:
 *
 * #[Env('prod',  concrete: RedisCache::class)]
 * #[Env('test',  concrete: ArrayCache::class)]
 * interface CacheInterface {}
 *
 * During boot scan these and call
 *   $repo->bindInterfaceForEnv($name, $interface, $concrete)
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Env
{
    public function __construct(
        public string $name,
        public string $concrete,
    ) {
    }
}
