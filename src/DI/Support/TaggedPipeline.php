<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\Exceptions\ContainerException;

final class TaggedPipeline
{
    private mixed $passable = null;

    /**
     * Associate a middleware tag with the runtime that will resolve its stages.
     */
    public function __construct(
        private readonly RuntimeContainerInterface $container,
        private readonly string $tag,
    ) {}

    /**
     * Set the payload forwarded through the tagged middleware chain.
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Transform the payload through tagged callable, handle or process stages in registration
     * order.
     *
     * @throws ContainerException
     */
    public function thenReturn(): mixed
    {
        $current = $this->passable;
        foreach ($this->container->tagged($this->tag) as $id => $service) {

            if (is_callable($service)) {
                $current = $service($current);

                continue;
            }

            if (is_object($service) && method_exists($service, 'handle')) {
                $current = $service->handle($current);

                continue;
            }

            if (is_object($service) && method_exists($service, 'process')) {
                $current = $service->process($current);

                continue;
            }

            throw new ContainerException(
                "Tagged service '$id' cannot participate in pipeline '{$this->tag}'.",
            );
        }

        return $current;
    }
}
