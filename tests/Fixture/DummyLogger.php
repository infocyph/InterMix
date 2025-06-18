<?php
namespace Infocyph\InterMix\Tests\Fixture;

final class DummyLogger
{
    public array $records = [];

    public function log(string $msg): void
    {
        $this->records[] = $msg;
    }
}
