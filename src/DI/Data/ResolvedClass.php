<?php

namespace Infocyph\InterMix\DI\Data;

final readonly class ResolvedClass
{
    public function __construct(
        public object $instance,
        public mixed  $returned           = null,
        public bool   $propertiesInjected = false,
    ) {
    }
}
