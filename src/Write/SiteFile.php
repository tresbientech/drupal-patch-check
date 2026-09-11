<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

/**
 * One file written under the site, its directory created first.
 */
class SiteFile
{
    /**
     * Writes the text, creating the directory the file sits in; false when either step failed.
     */
    public static function put(string $full, string $body): bool
    {
        $directory = \dirname($full);

        return (\is_dir($directory) || @\mkdir($directory, 0o777, true) || \is_dir($directory))
            && false !== @\file_put_contents($full, $body);
    }
}
