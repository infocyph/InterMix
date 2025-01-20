<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * Stores logs in-memory for test verification.
 */
class TestLoggerStorage
{
    public static array $logs = [];

    public static function reset(): void
    {
        self::$logs = [];
    }
}
