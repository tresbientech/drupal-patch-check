<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Assert;

/**
 * Printed output committed to a file, so a change to it is a diff.
 *
 * A phase that means to change the words runs the suite once with
 * `UPDATE_GOLDEN=1` and reads the diff. Every other phase leaves the files
 * alone, and the comparison catches a line nobody meant to move.
 */
trait Golden
{
    /**
     * @param list<string>|string $actual
     */
    private static function assertGolden(string $name, array|string $actual): void
    {
        $path = __DIR__.'/golden/'.$name.'.txt';
        $text = (\is_array($actual) ? \implode("\n", $actual) : $actual)."\n";
        if (false !== \getenv('UPDATE_GOLDEN')) {
            \file_put_contents($path, $text);
        }
        Assert::assertFileExists($path, $name.': run the suite once with UPDATE_GOLDEN=1 to write it');
        Assert::assertSame(\file_get_contents($path), $text, $name);
    }
}
