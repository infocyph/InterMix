<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExampleAttr
{
    public function __construct(public string $value)
    {
    }
}
