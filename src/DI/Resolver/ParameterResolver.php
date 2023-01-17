<?php

namespace AbmmHasan\OOF\DI\Resolver;

use Closure;

class ParameterResolver
{
    public function __construct(
        private DependencyResolver $resolver
    ) {
    }

    public function resolveByDefinition(mixed $definition): mixed
    {
        if (is_string($definition) && str_contains($definition, '::')) {
            $definition = explode('::', $definition, 2);
        }

//        if()

        if ($definition instanceof Closure) {
            return $this->resolver->closureSettler($definition, []);
        }

        return $definition;
    }
}
