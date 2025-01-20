<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * A simple logger implementing LoggerInterface.
 * In tests, we store logs in a static array to avoid file IO.
 */
class FileLogger implements LoggerInterface
{
    private string $filePath;

    public function __construct(string $filePath = '/tmp/app.log')
    {
        $this->filePath = $filePath;
    }

    public function log(string $message): void
    {
        // Instead of writing to a real file, store in a static array for test verification
        TestLoggerStorage::$logs[] = "[FileLogger:{$this->filePath}] $message";
    }
}
