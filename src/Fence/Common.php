<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Common
{
    /**
     * Prevents cloning the instances.
     *
     * @return void
     */
    public function __clone()
    {
    }

    /**
     * Prevents unserializing the instances.
     *
     * @return void
     */
    public function __wakeup()
    {
    }

    /**
     * Requirement checker
     *
     * @param array|null $constraints
     * @throws Exception
     */
    protected static function __checkRequirements(array $constraints = null)
    {
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
