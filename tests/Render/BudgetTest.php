<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Budget;

final class BudgetTest extends TestCase
{
    public function testAWidthInsideTheRangeIsKept(): void
    {
        self::assertSame(100, Budget::clamp(100));
    }

    public function testANarrowTerminalIsRaisedToTheFloor(): void
    {
        self::assertSame(Budget::MIN_WIDTH, Budget::clamp(40));
    }

    public function testAWideTerminalIsCutToTheCeiling(): void
    {
        self::assertSame(Budget::MAX_WIDTH, Budget::clamp(400));
    }

    public function testATerminalThatDidNotSayIsRaisedToTheFloor(): void
    {
        self::assertSame(Budget::MIN_WIDTH, Budget::clamp(0));
    }

    public function testTheTitleTakesWhatTheFilenameLeaves(): void
    {
        $narrow = Budget::title(100, 30);
        $wide = Budget::title(100, 10);

        self::assertSame(20, $wide - $narrow, 'a shorter filename gives the title its room');
    }

    public function testAWiderTerminalGivesTheTitleMoreRoom(): void
    {
        self::assertGreaterThan(Budget::title(80, 20), Budget::title(120, 20));
    }

    public function testTheTitleNeverCollapses(): void
    {
        self::assertGreaterThanOrEqual(20, Budget::title(80, 90));
    }

    public function testTheNarrowestRowFitsTheFloor(): void
    {
        $row = Budget::PREFIX + Budget::title(Budget::MIN_WIDTH, Budget::TRAILING_MAX) + 2 + Budget::TRAILING_MAX;

        self::assertLessThanOrEqual(Budget::MIN_WIDTH, $row);
    }

    public function testTextThatFitsIsLeftWhole(): void
    {
        self::assertSame('Fix the alter hook', Budget::fit('Fix the alter hook', 18));
    }

    public function testTextAtTheLimitIsLeftWhole(): void
    {
        self::assertSame('abcde', Budget::fit('abcde', 5));
    }

    public function testTextPastTheLimitIsShortened(): void
    {
        self::assertSame('abcd…', Budget::fit('abcdef', 5));
    }

    public function testAShortenedStringIsExactlyTheWidth(): void
    {
        self::assertSame(5, \mb_strlen(Budget::fit('abcdefghij', 5)));
    }

    public function testAMultiByteTitleIsCutOnCharactersNotBytes(): void
    {
        $cut = Budget::fit('Ajoute une préférence', 10);

        self::assertSame(10, \mb_strlen($cut));
        self::assertSame($cut, \mb_convert_encoding($cut, 'UTF-8', 'UTF-8'), 'the cut left valid UTF-8');
    }

    public function testASpaceBeforeTheCutIsRemoved(): void
    {
        self::assertSame('Fix the…', Budget::fit('Fix the alter hook', 8));
    }

    public function testAWidthOfOneIsJustTheEllipsis(): void
    {
        self::assertSame('…', Budget::fit('abcdef', 1));
    }

    public function testAWidthOfZeroIsEmpty(): void
    {
        self::assertSame('', Budget::fit('abcdef', 0));
    }

    public function testPaddingFillsToTheWidth(): void
    {
        self::assertSame('ab   ', Budget::pad('ab', 5));
    }

    public function testTextAtTheWidthIsNotPadded(): void
    {
        self::assertSame('abcde', Budget::pad('abcde', 5));
    }

    public function testTextPastTheWidthIsLeftAlone(): void
    {
        self::assertSame('abcdefg', Budget::pad('abcdefg', 5));
    }

    public function testPaddingCountsCharactersNotBytes(): void
    {
        self::assertSame(5, \mb_strlen(Budget::pad('préf', 5)));
    }

    public function testTheDetailIndentMatchesThePrefix(): void
    {
        self::assertSame(Budget::PREFIX, \strlen(Budget::detailIndent()));
    }
}
