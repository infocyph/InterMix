<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * If ClassA also depends on ClassB, it forms a cycle.
 */
class ServiceB
{
    public function __construct(ServiceA $a)
    {
        // cycle
    }
}
