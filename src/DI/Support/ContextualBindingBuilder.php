<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

final class ContextualBindingBuilder
{
    private ?string $dependency = null;

    /** @internal */
    public function __construct(
        private readonly Container|ContainerBuilder $owner,
        private readonly Repository $repository,
        private readonly string $consumer,
    ) {}

    /**
     * Transitional 10.x contextual binding terminal.
     *
     * New builder configuration must use the explicit giveClass(),
     * giveFactory(), giveReference(), or giveValue() terminals.
     *
     * @internal
     */
    public function give(mixed $implementation): Container
    {
        if (!$this->owner instanceof Container) {
            throw new ContainerException(
                'ContainerBuilder contextual bindings require an explicit binding terminal.',
            );
        }

        $dependency = $this->selectedDependency('give(...)');
        $this->repository->setContextualBinding($this->consumer, $dependency, $implementation);

        return $this->owner;
    }

    public function giveClass(string $class): Container|ContainerBuilder
    {
        if ($class === '' || (!class_exists($class) && !interface_exists($class))) {
            throw new ContainerException("Contextual class '{$class}' does not exist.");
        }

        return $this->store($class);
    }

    public function giveFactory(Closure|FactoryDefinition $factory): Container|ContainerBuilder
    {
        return $this->store(
            $factory instanceof Closure
                ? new RuntimeFactoryDefinition($factory)
                : $factory,
        );
    }

    public function giveReference(string $id): Container|ContainerBuilder
    {
        if ($id === '') {
            throw new ContainerException('Contextual service reference must be a non-empty string.');
        }

        return $this->store(new ServiceReference($id));
    }

    public function giveValue(mixed $value): Container|ContainerBuilder
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

    private function selectedDependency(string $terminal): string
    {
        if ($this->dependency === null) {
            throw new ContainerException(
                "Contextual binding requires needs(<dependency>) before {$terminal}.",
            );
        }

        return $this->dependency;
    }

    private function store(mixed $binding): Container|ContainerBuilder
    {
        $dependency = $this->selectedDependency('selecting a binding kind');
        $this->repository->setContextualBinding($this->consumer, $dependency, $binding);

        return $this->owner;
    }
}
