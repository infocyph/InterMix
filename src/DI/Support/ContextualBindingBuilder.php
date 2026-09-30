<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

final class ContextualBindingBuilder
{
    private ?string $dependency = null;

    /** @internal */
    public function __construct(
        private readonly ContainerBuilder $builder,
        private readonly Repository $repository,
        private readonly string $consumer,
    ) {}

    /** @param class-string $class */
    public function giveClass(string $class): ContainerBuilder
    {
        if ($class === '' || (!class_exists($class) && !interface_exists($class))) {
            throw new ContainerException("Contextual class '{$class}' does not exist.");
        }

        return $this->store($class);
    }

    public function giveFactory(Closure|FactoryDefinition $factory): ContainerBuilder
    {
        return $this->store(
            $factory instanceof Closure
                ? new RuntimeFactoryDefinition($factory)
                : $factory,
        );
    }

    public function giveReference(string $id): ContainerBuilder
    {
        if ($id === '') {
            throw new ContainerException('Contextual service reference must be a non-empty string.');
        }

        return $this->store(new ServiceReference($id));
    }

    public function giveValue(mixed $value): ContainerBuilder
    {
        return $this->store(new ValueDefinition($value));
    }

    public function needs(string $dependency): self
    {
        if ($dependency === '') {
            throw new ContainerException('Contextual dependency must be a non-empty string.');
        }

        $this->dependency = $dependency;

        return $this;
    }

    private function store(mixed $binding): ContainerBuilder
    {
        if ($this->dependency === null) {
            throw new ContainerException(
                'Contextual binding requires needs(<dependency>) before selecting a binding kind.',
            );
        }

        $this->repository->setContextualBinding($this->consumer, $this->dependency, $binding);

        return $this->builder;
    }
}
