<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

interface LoggerInterface
{
    public function log(string $message): void;
}
