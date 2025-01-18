<?php

namespace Infocyph\InterMix\Fence;

use Exception;

trait Common
{
    /**
     * Checks the given array of constraints and throws exceptions if any requirements are not met.
     *
     * @param  array|null  $constraints  The array of constraints to check.
     *
     * @throws Exception If any required extensions or classes are missing.
     */
    public static function checkRequirements(?array $constraints = null): void
    {
        if ($constraints === null) {
            return;
        }

        $missingExtensions = [];
        $missingClasses = [];

        if (isset($constraints['extensions'])) {
            $constraints['extensions'] = (array) $constraints['extensions'];
            $loadedExtensions = get_loaded_extensions();
            $missingExtensions = array_diff($constraints['extensions'], $loadedExtensions);
        }

        if (isset($constraints['classes'])) {
            $constraints['classes'] = (array) $constraints['classes'];
            $declaredClasses = get_declared_classes();
            $missingClasses = array_diff($constraints['classes'], $declaredClasses);
        }

        if ($missingExtensions || $missingClasses) {
            throw new Exception(
                sprintf(
                    'Requirements not met. %s%s',
                    $missingExtensions ? 'Extensions not loaded: '.implode(', ', $missingExtensions) : '',
                    $missingClasses ? ' Undeclared Classes: '.implode(', ', $missingClasses) : ''
                )
            );
        }
    }
}
