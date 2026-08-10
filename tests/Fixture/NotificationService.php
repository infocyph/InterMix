<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Inject;

class NotificationService
{
    #[Inject(FileLogger::class)]
    public LoggerInterface $logger;

    public function notify(string $message): void
    {
        $this->logger->log('Notification: '.$message);
    }
}
