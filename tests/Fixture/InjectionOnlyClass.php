<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

class InjectionOnlyClass
{
    public function __construct()
    {
        // No dependencies
    }
}
