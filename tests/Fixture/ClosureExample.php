<?php

namespace Infocyph\InterMix\Tests\Fixture;

class ClosureExample
{
    public function __invoke(string $message): string
    {
        return "ClosureExample says: $message";
    }
}
