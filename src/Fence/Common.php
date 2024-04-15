<?php

namespace Infocyph\InterMix\Fence;

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

        if (isset($constraints['extensions'])) {
            $constraints['extensions'] = (array)$constraints['extensions'];
            $commonExtensions = array_intersect(get_loaded_extensions(), $constraints['extensions']);
            if (count($commonExtensions) !== count($constraints['extensions'])) {
                throw new Exception(
                    'Missing extensions: ' . implode(
                        ', ',
                        array_diff($constraints['extensions'], $commonExtensions)
                    )
                );
            }
        }

        if (isset($constraints['classes'])) {
            $constraints['classes'] = (array)$constraints['classes'];
            $loadedClasses = array_intersect(get_declared_classes(), $constraints['classes']);
            if (count($loadedClasses) !== count($constraints['classes'])) {
                throw new Exception(
                    'Missing classes: ' . implode(
                        ', ',
                        array_diff($constraints['classes'], $loadedClasses)
                    )
                );
            }
        }
    }
}
