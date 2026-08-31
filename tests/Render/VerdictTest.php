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
        yield 'conflicts' => ['conflicts', '!', 0];
        yield 'unknown' => ['unknown', '?', 1];
        yield 'applies' => ['applies', '·', 2];
        yield 'merged' => ['merged', '✓', 3];
    }

    public function testTheWorkSortsAboveTheFinishedRows(): void
    {
        self::assertLessThan(Verdict::rank('applies'), Verdict::rank('conflicts'));
        self::assertLessThan(Verdict::rank('applies'), Verdict::rank('unknown'));
        self::assertLessThan(Verdict::rank('merged'), Verdict::rank('applies'));
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
            Verdict::rank('applies'),
            Verdict::rank('needs-a-human'),
            'an unknown verdict counts as work, so it must not sort with the finished rows',
        );
    }

    public function testAMarkIsWrappedInItsColour(): void
    {
        self::assertSame('<error>!</error>', Verdict::marked('conflicts'));
        self::assertSame('<comment>?</comment>', Verdict::marked('unknown'));
        self::assertSame('<info>✓</info>', Verdict::marked('merged'));
    }

    public function testAMarkWithNoColourIsPrintedBare(): void
    {
        self::assertSame('', Verdict::tag('applies'));
        self::assertSame('·', Verdict::marked('applies'));
    }

    public function testEveryMarkIsDistinct(): void
    {
        $marks = \array_map(
            static fn (string $verdict): string => Verdict::mark($verdict),
            ['conflicts', 'unknown', 'applies', 'merged'],
        );

        self::assertSame($marks, \array_unique($marks));
    }
}
