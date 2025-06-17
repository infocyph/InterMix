<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

enum Lifetime: string
{
    case Singleton = 'singleton';   // one per container
    case Transient = 'transient';   // always build fresh
    case Scoped = 'scoped';      // one per logical scope (fibre / request)
}
