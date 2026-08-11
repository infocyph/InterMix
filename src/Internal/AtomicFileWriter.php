<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Internal;

use RuntimeException;

/**
 * @internal
 */
final class AtomicFileWriter
{
    /**
     * @param string $filePath Final artifact destination.
     * @param string $contents Complete generated contents.
     * @param null|callable(string): void $validate Receives the staged path before activation.
     * @param int $mode Final file permission mode.
     */
    public static function write(
        string $filePath,
        string $contents,
        ?callable $validate = null,
        int $mode = 0644,
    ): void {
        $directory = realpath(dirname($filePath));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException("Output directory does not exist for '$filePath'.");
        }

        $temporaryPath = tempnam($directory, '.intermix-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create a temporary file for '$filePath'.");
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new RuntimeException("Unable to write complete generated file '$filePath'.");
            }

            if ($validate !== null) {
                $validate($temporaryPath);
            }

            if (!chmod($temporaryPath, $mode)) {
                throw new RuntimeException("Unable to set generated file permissions for '$filePath'.");
            }

            if (!rename($temporaryPath, $filePath)) {
                throw new RuntimeException("Unable to atomically replace generated file '$filePath'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
