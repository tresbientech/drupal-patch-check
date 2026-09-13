<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a command test's site does to the process it runs in.
 */
#[CoversClass(SiteFixture::class)]
class SiteFixtureTest extends TestCase
{
    // A case that fails leaves the next one where it found it, so a deleted
    // site cannot become the working directory of the rest of the suite.
    public function testASiteLeavesTheWorkingDirectoryAlone(): void
    {
        $before = \getcwd();
        $site = new SiteFixture();

        $site->enter('http://127.0.0.1:1');
        self::assertSame($before, \getcwd());

        $site->leave();
        self::assertSame($before, \getcwd());
    }

    public function testASiteNamesItselfThroughTheComposerVariable(): void
    {
        $previous = \getenv('COMPOSER');
        $site = new SiteFixture();

        $site->enter('http://127.0.0.1:1');
        self::assertSame($site->root.'/composer.json', \getenv('COMPOSER'));

        $site->leave();
        self::assertSame($previous, \getenv('COMPOSER'));
    }
}
