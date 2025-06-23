<?php

namespace Infocyph\InterMix\Tests\Fixture;

#[Attribute(Attribute::TARGET_CLASS)]
class LogicOnlyAttr
{
    public function __construct(public string $level = 'info')
    {
    }
}
