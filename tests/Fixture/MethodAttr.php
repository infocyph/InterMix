<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class MethodAttr
{
    public function __construct(public string $message)
    {
    }
}
