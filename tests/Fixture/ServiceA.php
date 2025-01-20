<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * If ClassB also depends on ClassA, it forms a cycle.
 */
class ServiceA
{
    public function __construct(ServiceB $b)
    {
        // cycle if b also depends on a
    }
}
