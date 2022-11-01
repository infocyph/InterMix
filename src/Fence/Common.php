<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Common
{
    /**
     * Requirement checker
     *
     * @param array|null $constraints
     * @throws Exception
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
