<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Infuse;

class NotificationService
{
    #[Infuse(FileLogger::class)]
    public LoggerInterface $logger;

    public function notify(string $message): void
    {
        $this->logger->log('Notification: '.$message);
    }
}
