<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * Simple class with NO constructor.
 * Tests basic instantiation via container.
 */
class BasicClass
{
    public function sayHello(): string
    {
        return 'Hello from BasicClass';
    }
}
