<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

class ListenerA
{
    public function __invoke(): string
    {
        return 'A';
    }
}
