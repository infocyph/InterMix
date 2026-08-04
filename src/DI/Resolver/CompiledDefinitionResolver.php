<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

/**
 * Dispatches compiled definitions before using the dynamic resolver.
 *
 * Keeping this lookup outside DefinitionResolver means containers without an
 * active compiled artifact pay no compiled-mode branch or repository lookup.
 *
 * @internal
 */
final class CompiledDefinitionResolver extends DefinitionResolver
{
    /**
     * @param string $name Registered definition identifier.
     */
    #[\Override]
    protected function resolveDefinition(string $name): mixed
    {
        $compiled = $this->repository->getCompiledResolver($name);
        if ($compiled !== null) {
            return $compiled($this->repository->container(), $name);
        }

        return parent::resolveDefinition($name);
    }
}
