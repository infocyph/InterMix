<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

#[Attribute(Attribute::TARGET_CLASS)]
class LogicOnlyAttr
{
    public function __construct(public string $level = 'info')
    {
    }
}
