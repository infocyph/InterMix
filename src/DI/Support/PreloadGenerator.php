<?php

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use ReflectionException;

final class PreloadGenerator
{
    /**
     * Generate a preload file at the given path, containing a list of class files
     * to include. The preload file is generated by scanning the container's
     * definitions and extracting the class names. The resulting file can be used
     * to preload classes in a production environment.
     *
     * @param Container $container
     * @param string $filePath
     * @throws ReflectionException
     */
    public function generate(Container $container, string $filePath): void
    {
        $repo = $container->getRepository();

        /* -------- gather class names --------------------------------- */
        $classes = array_keys($repo->getClassResource());

        foreach ($repo->getFunctionReference() as $def) {
            if (is_string($def) && class_exists($def)) {
                $classes[] = $def;
            } elseif (is_array($def) && isset($def[0]) && is_string($def[0]) && class_exists($def[0])) {
                $classes[] = $def[0];
            }
        }

        /* -------- convert to file paths ------------------------------ */
        $paths = [];
        foreach (array_unique($classes) as $fqcn) {
            class_exists($fqcn);
            $f = (ReflectionResource::getClassReflection($fqcn))->getFileName();
            if ($f) {
                $paths[] = $f;
            }
        }

        /* -------- emit preload file ---------------------------------- */
        $list = var_export($paths, true);
        $code = <<<PHP
            <?php
            foreach ($list as \$file) {
                function_exists('opcache_is_script_cached') && opcache_is_script_cached(\$file) && continue;
                require_once \$file;
                opcache_compile_file(\$file);
            }
            PHP;

        file_put_contents($filePath, $code);
    }
}
