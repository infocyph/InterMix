<?php

namespace Infocyph\InterMix\Tests\Fixture;

class ListenerB
{
    public function __invoke(): string
    {
        return 'B';
    }
}
