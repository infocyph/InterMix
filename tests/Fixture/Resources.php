<?php

namespace Infocyph\InterMix\Tests\Fixture;

final class Resources
{
    public $stream;
    public function __construct()
    {
        $this->stream = \fopen('php://memory', 'rb');
    }
}
