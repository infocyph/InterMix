<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * Tests multiple constructor args (scalar, interface optional).
 */
class MultiConstructorArgsClass
{
    public function __construct(
        public string $name,
        public int $count,
        public ?LoggerInterface $logger = null
    ) {
        // ...
    }

    public function info(): string
    {
        $loggerClass = $this->logger ? get_class($this->logger) : 'none';
        return "Name={$this->name}, Count={$this->count}, Logger={$loggerClass}";
    }
}
