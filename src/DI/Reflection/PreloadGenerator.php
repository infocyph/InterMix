<?php

namespace Infocyph\InterMix\DI\Reflection;

use Infocyph\InterMix\DI\Container;

final class PreloadGenerator
{
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
                require_once \$file;
            }
            PHP;

        file_put_contents($filePath, $code);
    }
}
