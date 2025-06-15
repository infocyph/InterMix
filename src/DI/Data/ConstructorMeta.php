<?php

namespace Infocyph\InterMix\DI\Data;

final readonly class ConstructorMeta
{
    public function __construct(
        public string $method = '__construct',
        /** @var array<string|int,mixed> */
        public array  $params = [],
    ) {
    }
}
