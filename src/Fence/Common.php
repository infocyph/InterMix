<?php

namespace AbmmHasan\InterMix\Fence;

use Exception;

trait Common
{
    /**
     * Checks the given array of constraints and throws exceptions if any requirements are not met.
     *
     * @param array|null $constraints The array of constraints to check.
     * @return void
     * @throws Exception If any required extensions or classes are missing.
     */
    protected static function checkRequirements(array $constraints = null): void
    {
        if ($constraints === null) {
            return;
        }

        if (!empty($constraints['extensions'])) {
            $commonExt = array_intersect(get_loaded_extensions(), $constraints['extensions']);
            $missingExt = array_diff($constraints['extensions'], $commonExt);
            if (!empty($missingExt)) {
                throw new Exception('Missing extensions: ' . implode(', ', $missingExt));
            }
        }

        if (!empty($constraints['classes'])) {
            $loadedClasses = array_intersect(get_declared_classes(), $constraints['classes']);
            $missingClasses = array_diff($constraints['classes'], $loadedClasses);
            if (!empty($missingClasses)) {
                throw new Exception('Missing classes: ' . implode(', ', $missingClasses));
            }
        }
    }
}
