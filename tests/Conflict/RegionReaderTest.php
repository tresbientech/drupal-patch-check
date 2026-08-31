<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Conflict;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Write\Decisions;

#[CoversClass(Decisions::class)]
final class RegionReaderTest extends TestCase
{
    public function testAFullyResolvedFileYieldsOneResolutionPerRegion(): void
    {
        $text = <<<'TXT'
            # drupatch: 2 unresolved region(s) in src/Form.php
            # drupatch: keep the region and end lines; replace what sits between them.
            # drupatch region 0 src/Form.php
                $decided = TRUE;
            # drupatch end 0 src/Form.php
            # drupatch region 1 src/Form.php
                $also = TRUE;
            # drupatch end 1 src/Form.php
            TXT;

        $got = Decisions::read($text, 'patches/a.conflict.patch');

        self::assertCount(2, $got);
        self::assertSame('src/Form.php', $got[0]['file']);
        self::assertSame(0, $got[0]['region']);
        self::assertSame('    $decided = TRUE;', $got[0]['text'] ?? '');
        self::assertSame(1, $got[1]['region']);
        self::assertSame('    $also = TRUE;', $got[1]['text'] ?? '');
    }

    public function testARegionStillHoldingMarkersIsNotAResolution(): void
    {
        $text = <<<'TXT'
            # drupatch region 0 src/Form.php
            <<<<<<< release src/Form.php:40
            new code
            =======
            patched code
            >>>>>>> patch
            # drupatch end 0 src/Form.php
            # drupatch region 1 src/Form.php
                $decided = TRUE;
            # drupatch end 1 src/Form.php
            TXT;

        $got = Decisions::read($text, 'patches/a.conflict.patch');

        self::assertCount(1, $got);
        self::assertSame(1, $got[0]['region']);
    }

    public function testAnUntouchedFileYieldsNothing(): void
    {
        $text = <<<'TXT'
            # drupatch region 0 src/Form.php
            <<<<<<< release src/Form.php:40
            new code
            =======
            patched code
            >>>>>>> patch
            # drupatch end 0 src/Form.php
            TXT;

        self::assertSame([], Decisions::read($text, 'patches/a.conflict.patch'));
    }

    public function testTextMatchingTheReleaseSideIsAResolutionLikeAnyOther(): void
    {
        $text = "# drupatch region 0 a.php\nnew code\n# drupatch end 0 a.php";

        $got = Decisions::read($text, 'patches/a.conflict.patch');

        self::assertCount(1, $got);
        self::assertSame('new code', $got[0]['text'] ?? '');
    }

    public function testAnEmptyRegionIsAResolutionCarryingNoText(): void
    {
        $text = "# drupatch region 0 a.php\n# drupatch end 0 a.php";

        $got = Decisions::read($text, 'patches/a.conflict.patch');

        self::assertCount(1, $got);
        self::assertSame('', $got[0]['text'] ?? '');
    }

    public function testTheDiffAboveTheRegionsIsIgnored(): void
    {
        $text = <<<'TXT'
            diff --git a/src/Other.php b/src/Other.php
            @@ -1,2 +1,3 @@
            +merged cleanly
            # drupatch region 0 a.php
            decided
            # drupatch end 0 a.php
            TXT;

        $got = Decisions::read($text, 'patches/a.conflict.patch');

        self::assertCount(1, $got);
        self::assertSame('decided', $got[0]['text'] ?? '');
    }

    public function testAnEndWithoutAnOpeningSentinelNamesTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('#patches/a\.conflict\.patch#');

        Decisions::read("decided\n# drupatch end 0 a.php", 'patches/a.conflict.patch');
    }

    public function testAnUnclosedRegionNamesTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('#patches/a\.conflict\.patch#');

        Decisions::read("# drupatch region 0 a.php\ndecided", 'patches/a.conflict.patch');
    }

    public function testAnEndNamingAnotherRegionNamesTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('#patches/a\.conflict\.patch#');

        Decisions::read("# drupatch region 0 a.php\ndecided\n# drupatch end 1 a.php", 'patches/a.conflict.patch');
    }

    public function testARegionOpeningInsideAnotherNamesTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('#patches/a\.conflict\.patch#');

        Decisions::read("# drupatch region 0 a.php\n# drupatch region 1 a.php\n# drupatch end 1 a.php", 'patches/a.conflict.patch');
    }
}
