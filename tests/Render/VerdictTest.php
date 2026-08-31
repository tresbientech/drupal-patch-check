<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Verdict;

final class VerdictTest extends TestCase
{
    /**
     * @dataProvider knownVerdicts
     */
    public function testAKnownVerdictHasAMarkAndARank(string $verdict, string $mark, int $rank): void
    {
        self::assertSame($mark, Verdict::mark($verdict));
        self::assertSame($rank, Verdict::rank($verdict));
        self::assertTrue(Verdict::isKnown($verdict));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function knownVerdicts(): iterable
    {
        yield 'needs-reroll' => ['needs-reroll', '!', 0];
        yield 'unknown' => ['unknown', '?', 1];
        yield 'still-needed' => ['still-needed', '·', 2];
        yield 'shipped' => ['shipped', '✓', 3];
    }

    public function testTheWorkSortsAboveTheFinishedRows(): void
    {
        self::assertLessThan(Verdict::rank('still-needed'), Verdict::rank('needs-reroll'));
        self::assertLessThan(Verdict::rank('still-needed'), Verdict::rank('unknown'));
        self::assertLessThan(Verdict::rank('shipped'), Verdict::rank('still-needed'));
    }

    public function testAVerdictTheServerAddedLaterIsNotKnown(): void
    {
        self::assertFalse(Verdict::isKnown('needs-a-human'));
    }

    public function testAVerdictTheServerAddedLaterStillHasAMark(): void
    {
        self::assertNotSame('', Verdict::mark('needs-a-human'));
    }

    public function testAVerdictTheServerAddedLaterSortsWithTheWork(): void
    {
        self::assertLessThan(
            Verdict::rank('still-needed'),
            Verdict::rank('needs-a-human'),
            'an unknown verdict counts as work, so it must not sort with the finished rows',
        );
    }

    public function testAMarkIsWrappedInItsColour(): void
    {
        self::assertSame('<error>!</error>', Verdict::marked('needs-reroll'));
        self::assertSame('<comment>?</comment>', Verdict::marked('unknown'));
        self::assertSame('<info>✓</info>', Verdict::marked('shipped'));
    }

    public function testAMarkWithNoColourIsPrintedBare(): void
    {
        self::assertSame('', Verdict::tag('still-needed'));
        self::assertSame('·', Verdict::marked('still-needed'));
    }

    public function testEveryMarkIsDistinct(): void
    {
        $marks = \array_map(
            static fn (string $verdict): string => Verdict::mark($verdict),
            ['needs-reroll', 'unknown', 'still-needed', 'shipped'],
        );

        self::assertSame($marks, \array_unique($marks));
    }
}
