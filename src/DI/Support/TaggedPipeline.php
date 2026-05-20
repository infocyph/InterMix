<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;

final class TaggedPipeline
{
    private mixed $passable = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $tag,
    ) {}

    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * @throws ContainerException
     */
    public function thenReturn(): mixed
    {
        $current = $this->passable;
        foreach ($this->container->findByTagLazy($this->tag) as $resolver) {
            $service = $resolver();

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
            }
        }

        return $current;
    }
}
