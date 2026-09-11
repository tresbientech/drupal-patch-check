<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

/**
 * Removes what a case wrote under the system temp directory.
 */
class Scratch
{
    /** Deletes a file, or a directory and everything under it. */
    public static function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\array_diff((array) \scandir($path), ['.', '..']) as $entry) {
                self::remove($path.'/'.$entry);
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    }
}
