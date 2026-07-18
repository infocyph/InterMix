<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

class BarService
{
    public function __construct(public FooService $foo)
    {
    }
}
