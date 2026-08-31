<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\NextSteps;

final class NextStepsTest extends TestCase
{
    public function testAPlanWithNothingToClearSuggestsNothing(): void
    {
        self::assertSame([], NextSteps::of(['still-needed' => 4]));
        self::assertSame([], NextSteps::lines(['still-needed' => 4]));
    }

    public function testAnEmptyPlanSuggestsNothing(): void
    {
        self::assertSame([], NextSteps::of([]));
    }

    public function testAnUnclearPlanSuggestsNothing(): void
    {
        self::assertSame([], NextSteps::of(['unknown' => 3]), 'no flag clears a verdict the service could not reach');
    }

    public function testARerollIsOfferedTheFlagThatWritesIt(): void
    {
        $steps = NextSteps::of(['needs-reroll' => 4]);

        self::assertCount(1, $steps);
        self::assertSame('--reroll', $steps[0]['flag']);
        self::assertStringContainsString('4', $steps[0]['effect']);
    }

    public function testOneRerollIsSpokenOfInTheSingular(): void
    {
        self::assertSame('writes the re-roll', NextSteps::of(['needs-reroll' => 1])[0]['effect']);
    }

    public function testAShippedPatchIsOfferedTheFlagThatDropsIt(): void
    {
        $steps = NextSteps::of(['shipped' => 3]);

        self::assertCount(1, $steps);
        self::assertSame('--fix', $steps[0]['flag']);
        self::assertStringContainsString('3', $steps[0]['effect']);
    }

    public function testOneShippedPatchIsSpokenOfInTheSingular(): void
    {
        self::assertStringContainsString('the shipped entry', NextSteps::of(['shipped' => 1])[0]['effect']);
    }

    public function testBothFindingsAreOfferedWorstFirst(): void
    {
        $steps = NextSteps::of(['shipped' => 3, 'needs-reroll' => 4]);

        self::assertSame(['--reroll', '--fix'], \array_column($steps, 'flag'));
    }

    public function testAZeroCountIsNotAFinding(): void
    {
        self::assertSame([], NextSteps::of(['needs-reroll' => 0, 'shipped' => 0]));
    }

    public function testEverySuggestionNamesTheCommandAndWhatItDoes(): void
    {
        foreach (NextSteps::lines(['needs-reroll' => 4, 'shipped' => 3]) as $line) {
            self::assertStringContainsString(NextSteps::COMMAND, $line);
        }
    }

    public function testTheFirstLineIsLabelledAndTheRestAreNot(): void
    {
        $lines = NextSteps::lines(['needs-reroll' => 4, 'shipped' => 3]);

        self::assertCount(2, $lines);
        self::assertStringContainsString('Next:', $lines[0]);
        self::assertStringNotContainsString('Next:', $lines[1]);
    }

    public function testTheCommandsLineUp(): void
    {
        $lines = NextSteps::lines(['needs-reroll' => 4, 'shipped' => 3]);

        self::assertSame(
            \strpos($lines[0], NextSteps::COMMAND),
            \strpos($lines[1], NextSteps::COMMAND),
            'the command column starts at the same offset on every line',
        );
    }

    public function testTheEffectsLineUp(): void
    {
        $lines = NextSteps::lines(['needs-reroll' => 4, 'shipped' => 3]);

        self::assertSame(
            \strpos($lines[0], 'writes'),
            \strpos($lines[1], 'drops'),
            'the effect column starts at the same offset on every line',
        );
    }

    public function testTheIndentIsHonoured(): void
    {
        self::assertStringStartsWith('    Next:', NextSteps::lines(['needs-reroll' => 1], '    ')[0]);
    }
}
