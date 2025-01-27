<?php

namespace Infocyph\InterMix\Tests\Fixture;

interface LoggerInterface
{
    public function log(string $message): void;
}
