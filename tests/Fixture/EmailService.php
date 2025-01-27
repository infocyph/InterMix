<?php

namespace Infocyph\InterMix\Tests\Fixture;

class EmailService
{
    private bool $initialized = false;

    public function __construct()
    {
        // no direct constructor injection
    }

    public function setConfig(array $config): void
    {
        // For testing method injection
        $this->initialized = true;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (! $this->initialized) {
            throw new \RuntimeException('EmailService not configured!');
        }
        // Suppose it "sends" mail
        return true;
    }
}
