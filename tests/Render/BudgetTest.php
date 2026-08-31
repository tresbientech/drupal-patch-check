<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Report;

final class BudgetTest extends TestCase
{
    public function testAWidthInsideTheRangeIsKept(): void
    {
        self::assertSame(100, Report::clamp(100));
    }

    public function testANarrowTerminalIsRaisedToTheFloor(): void
    {
        self::assertSame(Report::MIN_WIDTH, Report::clamp(40));
    }

    public function testAWideTerminalIsCutToTheCeiling(): void
    {
        self::assertSame(Report::MAX_WIDTH, Report::clamp(400));
    }

    public function testATerminalThatDidNotSayIsRaisedToTheFloor(): void
    {
        self::assertSame(Report::MIN_WIDTH, Report::clamp(0));
    }

    public function testTheTitleTakesWhatTheFilenameLeaves(): void
    {
        $narrow = Report::title(100, 30);
        $wide = Report::title(100, 10);

        self::assertSame(20, $wide - $narrow, 'a shorter filename gives the title its room');
    }

    public function testAWiderTerminalGivesTheTitleMoreRoom(): void
    {
        self::assertGreaterThan(Report::title(80, 20), Report::title(120, 20));
    }

    public function testTheTitleNeverCollapses(): void
    {
        self::assertGreaterThanOrEqual(20, Report::title(80, 90));
    }

    public function testTheNarrowestRowFitsTheFloor(): void
    {
        $row = Report::PREFIX + Report::title(Report::MIN_WIDTH, Report::TRAILING_MAX) + 2 + Report::TRAILING_MAX;

        self::assertLessThanOrEqual(Report::MIN_WIDTH, $row);
    }

    public function testTextThatFitsIsLeftWhole(): void
    {
        self::assertSame('Fix the alter hook', Report::fit('Fix the alter hook', 18));
    }

    public function testTextAtTheLimitIsLeftWhole(): void
    {
        self::assertSame('abcde', Report::fit('abcde', 5));
    }

    public function testTextPastTheLimitIsShortened(): void
    {
        self::assertSame('abcd…', Report::fit('abcdef', 5));
    }

    public function testAShortenedStringIsExactlyTheWidth(): void
    {
        self::assertSame(5, \mb_strlen(Report::fit('abcdefghij', 5)));
    }

    public function testAMultiByteTitleIsCutOnCharactersNotBytes(): void
    {
        $cut = Report::fit('Ajoute une préférence', 10);

        self::assertSame(10, \mb_strlen($cut));
        self::assertSame($cut, \mb_convert_encoding($cut, 'UTF-8', 'UTF-8'), 'the cut left valid UTF-8');
    }

    public function testASpaceBeforeTheCutIsRemoved(): void
    {
        self::assertSame('Fix the…', Report::fit('Fix the alter hook', 8));
    }

    public function testAWidthOfOneIsJustTheEllipsis(): void
    {
        self::assertSame('…', Report::fit('abcdef', 1));
    }

    public function testAWidthOfZeroIsEmpty(): void
    {
        self::assertSame('', Report::fit('abcdef', 0));
    }

    public function testPaddingFillsToTheWidth(): void
    {
        self::assertSame('ab   ', Report::pad('ab', 5));
    }

    public function testTextAtTheWidthIsNotPadded(): void
    {
        self::assertSame('abcde', Report::pad('abcde', 5));
    }

    public function testTextPastTheWidthIsLeftAlone(): void
    {
        self::assertSame('abcdefg', Report::pad('abcdefg', 5));
    }

    public function testPaddingCountsCharactersNotBytes(): void
    {
        self::assertSame(5, \mb_strlen(Report::pad('préf', 5)));
    }

    public function testTheDetailIndentMatchesThePrefix(): void
    {
        self::assertSame(Report::PREFIX, \strlen(Report::detailIndent()));
    }
}
