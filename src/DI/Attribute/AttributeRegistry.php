<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
use Reflector;

final class AttributeRegistry
{
    /** @var array<class-string, AttributeResolverInterface> */
    private array $map = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Registers an attribute resolver class.
     *
     * The resolver class is associated with the given attribute class.
     *
     * @param string $attributeFqcn The fully qualified class name of the attribute.
     * @param string $resolverFqcn The fully qualified class name of the resolver.
     *
     * @throws ContainerException
     */
    public function register(string $attributeFqcn, string $resolverFqcn): void
    {
        if (!class_exists($attributeFqcn) || !class_exists($resolverFqcn)) {
            throw new ContainerException("Attribute or resolver class missing");
        }
        $this->map[$attributeFqcn] = new $resolverFqcn();
    }

    /**
     * Resolves the given attribute instance.
     *
     * Looks up the associated resolver in the map and calls its resolve method.
     * If the resolver is not found or returns null, the method returns null.
     *
     * @param object $attributeInstance The attribute instance to resolve.
     * @param Reflector $target The target of the attribute (e.g. a class, method, or property).
     * @return mixed The resolved value or null if not possible.
     */
    public function resolve(
        object $attributeInstance,
        Reflector $target,
    ): mixed {
        $resolver = $this->map[$attributeInstance::class] ?? null;

        return $resolver?->resolve($attributeInstance, $target, $this->container);
    }

    /**
     * Returns whether an attribute resolver is registered for the given attribute class.
     *
     * @param string $attributeFqcn The fully qualified class name of the attribute.
     *
     * @return bool true if the attribute resolver is registered, false otherwise.
     */
    public function has(string $attributeFqcn): bool
    {
        return isset($this->map[$attributeFqcn]);
    }
}
