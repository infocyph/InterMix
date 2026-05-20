<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;

final class ContextualBindingBuilder
{
    private ?string $dependency = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $consumer,
    ) {}

    /**
     * @throws ContainerException
     */
    public function give(mixed $implementation): Container
    {
        if ($this->dependency === null || $this->dependency === '') {
            throw new ContainerException('Contextual binding requires needs(<dependency>) before give(...).');
        }

        $this->container
            ->getRepository()
            ->setContextualBinding($this->consumer, $this->dependency, $implementation);

        return $this->container;
    }

    public function needs(string $dependency): self
    {
        $this->dependency = $dependency;

        return $this;
    }
}
