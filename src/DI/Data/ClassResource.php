<?php

namespace Infocyph\InterMix\DI\Data;

final readonly class ClassResource
{
    public function __construct(
        public ConstructorMeta $ctor       = new ConstructorMeta(),
        public ?MethodMeta     $methodMeta = null,
        /** @var array<string,mixed> */
        public array           $properties = [],
    ) {
    }
}
