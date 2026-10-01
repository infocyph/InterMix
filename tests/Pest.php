<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\RuntimeContainerInterface;

require_once __DIR__ . '/../src/functions.php';

/**
 * Package-internal access retained only for migrated low-level scope-store tests.
 *
 * @param array<string, mixed> $instances
 */
function testEnterScope(
    RuntimeContainerInterface $runtime,
    string $scope,
    array $instances = [],
): RuntimeContainerInterface {
    $owner = $runtime instanceof ProductionContainer
        ? ProductionContainer::class
        : Container::class;
    $enter = Closure::bind(
        function (string $scope, array $instances): void {
            $this->enterScope($scope, $instances);
        },
        $runtime,
        $owner,
    );
    $enter($scope, $instances);

    return $runtime;
}

/** Package-internal access retained only for migrated low-level scope-store tests. */
function testLeaveScope(RuntimeContainerInterface $runtime): RuntimeContainerInterface
{
    $owner = $runtime instanceof ProductionContainer
        ? ProductionContainer::class
        : Container::class;
    $leave = Closure::bind(
        function (): void {
            $this->leaveScope();
        },
        $runtime,
        $owner,
    );
    $leave();

    return $runtime;
}
