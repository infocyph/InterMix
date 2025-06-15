<?php

namespace Infocyph\InterMix\DI\Data;

final readonly class MethodMeta
{
    public function __construct(
        public string $name,
        /** @var array<string|int,mixed> */
        public array  $params = [],
    ) {
    }
}
