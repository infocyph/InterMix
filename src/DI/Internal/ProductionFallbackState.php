<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
use Throwable;

/** @internal */
final class ProductionFallbackState
{
    /**
     * @param array<int, string> $compiledIds
     * @param array<string, array{exists: bool, definition: mixed, lifetime: \Infocyph\InterMix\DI\Support\LifetimeEnum, tags: array<int, string>}> $existing
     * @return array<string, array{exists: bool, definition: mixed, lifetime: \Infocyph\InterMix\DI\Support\LifetimeEnum, tags: array<int, string>}>
     */
    public static function captureDefinitions(Container $fallback, array $compiledIds, array $existing): array
    {
        $repository = $fallback->getRepository();
        foreach ($compiledIds as $id) {
            if (isset($existing[$id])) {
                continue;
            }

            $exists = $repository->hasFunctionReference($id);
            $meta = $repository->getDefinitionMeta($id);
            $existing[$id] = [
                'exists' => $exists,
                'definition' => $exists ? $repository->getFunctionDefinition($id) : null,
                'lifetime' => $meta['lifetime'],
                'tags' => $meta['tags'],
            ];
        }

        return $existing;
    }

    /**
     * @param array<string, array{exists: bool, definition: mixed, lifetime: \Infocyph\InterMix\DI\Support\LifetimeEnum, tags: array<int, string>}> $snapshots
     * @param array<string, mixed> $bridges
     * @return array<string, true>
     */
    public static function restoreDefinitions(Container $fallback, array $snapshots, array $bridges): array
    {
        $overridden = [];
        $repository = $fallback->getRepository();
        foreach ($snapshots as $id => $snapshot) {
            if (($bridges[$id] ?? null) !== $repository->getFunctionDefinition($id)) {
                $overridden[$id] = true;

                continue;
            }
            if (!$snapshot['exists']) {
                $fallback->unbind($id);

                continue;
            }

            $fallback->bind(
                $id,
                $snapshot['definition'],
                $snapshot['lifetime'],
                $snapshot['tags'],
            );
        }

        return $overridden;
    }

    public static function synchronizeScopes(Container $fallback, ScopeState $current): void
    {
        $scopes = [];
        for ($scope = $current; $scope->parent instanceof ScopeState; $scope = $scope->parent) {
            $scopes[] = $scope;
        }

        try {
            foreach (array_reverse($scopes) as $scope) {
                $fallback->enterScope($scope->name, $scope->rawSeeds);
            }
        } catch (Throwable $throwable) {
            throw new ContainerException('Unable to synchronize production fallback scope state.', previous: $throwable);
        }
    }

    /**
     * @param array<string, true> $overridden
     * @param array<string, mixed> $singletons
     * @param array<int, string> $ids
     */
    public static function transferCompiledState(
        Container $fallback,
        array $overridden,
        array $singletons,
        array $ids,
        ScopeState $current,
    ): void {
        $repository = $fallback->getRepository();
        foreach ($singletons as $id => $value) {
            if (isset($overridden[$id])) {
                continue;
            }
            $repository->setResolved($id, $value);
            $repository->markResolved($id);
        }

        for ($scope = $current; $scope instanceof ScopeState; $scope = $scope->parent) {
            foreach ($scope->resolved as $slot => $value) {
                $id = $ids[$slot] ?? null;
                if (!is_string($id) || isset($overridden[$id])) {
                    continue;
                }
                $repository->setResolvedScoped($scope->name, $id, $value);
                $repository->markResolved($id);
            }
        }
    }
}
