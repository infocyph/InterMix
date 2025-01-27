<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * Example of constructor injection with an interface + scalar param.
 */
class UserService
{
    private LoggerInterface $logger;
    private string $dbName;

    public function __construct(LoggerInterface $logger, string $dbName = 'test_db')
    {
        $this->logger = $logger;
        $this->dbName = $dbName;
    }

    public function createUser(array $userData): bool
    {
        $this->logger->log('Creating user in '.$this->dbName);
        return true;
    }

    public function getDbName(): string
    {
        return $this->dbName;
    }
}
