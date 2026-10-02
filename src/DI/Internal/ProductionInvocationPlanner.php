<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionMethod;
use ReflectionNamedType;

/** @internal */
final class ProductionInvocationPlanner
{
    private const int PLAN_CACHE_LIMIT = 512;

    /** @var array<string, true> */
    private array $compiledIds;

    /** @var array<string, list<string>|false> */
    private array $plans = [];

    /** @param array<int, string> $compiledIds */
    public function __construct(
        private readonly Repository $repository,
        array $compiledIds,
    ) {
        $this->compiledIds = array_fill_keys($compiledIds, true);
    }

    /** @param array<int|string, mixed> $arguments */
    public function invoke(
        callable $callable,
        array $arguments,
        RuntimeContainerInterface $container,
        mixed &$result,
    ): bool {
        if ($arguments !== [] || !is_array($callable) || !is_object($callable[0])) {
            return false;
        }

        $dependencies = $this->dependencies($callable[0], $callable[1]);
        if ($dependencies === null) {
            return false;
        }

        $resolved = [];
        foreach ($dependencies as $dependency) {
            $resolved[] = $container->get($dependency);
        }

        $result = $callable(...$resolved);

        return true;
    }

    /** @return list<string>|null */
    private function dependencies(object $target, string $method): ?array
    {
        $key = $target::class . '::' . strtolower($method);
        if (array_key_exists($key, $this->plans)) {
            $cached = $this->plans[$key];

            return $cached === false ? null : $cached;
        }

        $dependencies = $this->plan($target, $method);
        if (count($this->plans) >= self::PLAN_CACHE_LIMIT) {
            unset($this->plans[array_key_first($this->plans)]);
        }
        $this->plans[$key] = $dependencies ?? false;

        return $dependencies;
    }

    private function hasMethodResources(string $targetClass, string $declaringClass): bool
    {
        $targetResources = $this->repository->getClassResourceFor($targetClass);
        if (array_key_exists('method', $targetResources)) {
            return true;
        }
        if ($declaringClass === $targetClass) {
            return false;
        }

        return array_key_exists(
            'method',
            $this->repository->getClassResourceFor($declaringClass),
        );
    }

    /** @return list<string>|null */
    private function plan(object $target, string $method): ?array
    {
        if ($this->repository->isTracingEnabled()
            || $this->repository->isMethodAttributeEnabled()
            || $this->repository->hasContextualBindings()
        ) {
            return null;
        }

        $reflection = ReflectionResource::getCallableReflection([$target, $method]);
        if (!$reflection instanceof ReflectionMethod || !$reflection->isPublic() || $reflection->isStatic()) {
            return null;
        }

        if ($this->hasMethodResources($target::class, $reflection->getDeclaringClass()->getName())) {
            return null;
        }

        $dependencies = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType
                || $type->isBuiltin()
                || $type->allowsNull()
                || $parameter->isVariadic()
                || $parameter->isPassedByReference()
                || $parameter->isDefaultValueAvailable()
            ) {
                return null;
            }

            $dependency = $type->getName();
            if (in_array($dependency, ['self', 'parent', 'static'], true)
                || !isset($this->compiledIds[$dependency])
            ) {
                return null;
            }

            $dependencies[] = $dependency;
        }

        return $dependencies;
    }
}
