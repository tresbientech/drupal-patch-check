<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Write\WorkingTree;

class NextStepsTest extends TestCase
{
    public function testAPlanWithNothingToClearSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps(['applies' => 4]));
        self::assertSame([], Report::nextStepLines(['applies' => 4]));
    }

    public function testAnEmptyPlanSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps([]));
    }

    public function testAnUnclearPlanSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps(['unknown' => 3]), 'no flag clears a verdict the service could not reach');
    }

    public function testARerollIsOfferedTheFlagThatWritesIt(): void
    {
        $steps = Report::nextSteps(['conflicts' => 4]);

        self::assertCount(1, $steps);
        self::assertSame('--write', $steps[0]['flag']);
        self::assertStringContainsString('4', $steps[0]['effect']);
    }

    public function testOneRerollIsSpokenOfInTheSingular(): void
    {
        self::assertSame('writes the re-roll', Report::nextSteps(['conflicts' => 1])[0]['effect']);
    }

    public function testAShippedPatchIsOfferedTheFlagThatDropsIt(): void
    {
        $steps = Report::nextSteps(['merged' => 3]);

        self::assertCount(1, $steps);
        self::assertSame('--fix', $steps[0]['flag']);
        self::assertStringContainsString('3', $steps[0]['effect']);
    }

    public function testOneShippedPatchIsSpokenOfInTheSingular(): void
    {
        self::assertStringContainsString('the shipped entry', Report::nextSteps(['merged' => 1])[0]['effect']);
    }

    public function testBothFindingsAreOfferedWorstFirst(): void
    {
        $steps = Report::nextSteps(['merged' => 3, 'conflicts' => 4]);

        self::assertSame(['--write', '--fix'], \array_column($steps, 'flag'));
    }

    public function testAZeroCountIsNotAFinding(): void
    {
        self::assertSame([], Report::nextSteps(['conflicts' => 0, 'merged' => 0]));
    }

    public function testEverySuggestionNamesTheCommandAndWhatItDoes(): void
    {
        foreach (Report::nextStepLines(['conflicts' => 4, 'merged' => 3]) as $line) {
            self::assertStringContainsString(Report::COMMAND, $line);
        }
    }

    public function testTheFirstLineIsLabelledAndTheRestAreNot(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 4, 'merged' => 3]);

        self::assertCount(2, $lines);
        self::assertStringContainsString('Next:', $lines[0]);
        self::assertStringNotContainsString('Next:', $lines[1]);
    }

    public function testTheCommandsLineUp(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 4, 'merged' => 3]);

        self::assertSame(
            \strpos($lines[0], Report::COMMAND),
            \strpos($lines[1], Report::COMMAND),
            'the command column starts at the same offset on every line',
        );
    }

    public function testTheEffectsLineUp(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 4, 'merged' => 3]);

        self::assertSame(
            \strpos($lines[0], 'writes'),
            \strpos($lines[1], 'drops'),
            'the effect column starts at the same offset on every line',
        );
    }

    public function testTheIndentIsHonoured(): void
    {
        self::assertStringStartsWith('    Next:', Report::nextStepLines(['conflicts' => 1], '    ')[0]);
    }

    public function testARunThatWroteEveryRerollSuggestsNoWriteStep(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.patch', 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true]], 'refused' => []];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], $wrote));
    }

    public function testARunThatLeftAConflictFileIsOfferedTheFlagThatFinishesIt(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false]], 'refused' => []];

        $steps = Report::nextSteps(['conflicts' => 1], $wrote);

        self::assertSame(['--resolve'], \array_column($steps, 'flag'));
        self::assertSame('sends the regions you decide in the conflict file', $steps[0]['effect']);
    }

    public function testSeveralConflictFilesAreCounted(): void
    {
        $wrote = ['written' => [
            ['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false],
            ['path' => 'patches/b.patch', 'status' => 'clean', 'package' => 'drupal/b', 'title' => 'Fix b', 'verified' => true],
            ['path' => 'patches/c.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/c', 'title' => 'Fix c', 'verified' => false],
        ], 'refused' => []];

        self::assertSame('sends the regions you decide in the 2 conflict files', Report::nextSteps(['conflicts' => 3], $wrote)[0]['effect']);
    }

    public function testTheConflictFileComesBeforeTheRefusal(): void
    {
        $wrote = [
            'written' => [['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false]],
            'refused' => [['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force']],
        ];

        self::assertSame(['--resolve', '--force'], \array_column(Report::nextSteps(['conflicts' => 2], $wrote), 'flag'));
    }

    public function testARunThatCouldNotReplaceAFileIsOfferedTheFlagThatLetsIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force']]];

        $steps = Report::nextSteps(['conflicts' => 1], $wrote);

        self::assertSame(['--force'], \array_column($steps, 'flag'));
        self::assertSame('replaces the file this run would not overwrite', $steps[0]['effect']);
    }

    public function testSeveralRefusalsAreCounted(): void
    {
        $wrote = ['written' => [], 'refused' => [
            ['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force'],
            ['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNTRACKED, 'lifts' => '--force'],
        ]];

        self::assertStringContainsString('2', Report::nextSteps(['conflicts' => 2], $wrote)[0]['effect']);
    }

    public function testARefusalNoFlagLiftsSuggestsNothing(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => 'the service built no re-roll for it', 'lifts' => '']]];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], $wrote));
    }

    public function testAShippedEntryIsStillOfferedAfterAWrite(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.patch', 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true]], 'refused' => []];

        self::assertSame(['--fix'], \array_column(Report::nextSteps(['merged' => 2, 'conflicts' => 1], $wrote), 'flag'));
    }

    public function testAUrlDeclarationIsOfferedTheFlagThatAdoptsIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => 'declared as a URL', 'lifts' => '--fix']]];

        $steps = Report::nextSteps([], $wrote);

        self::assertSame(['--fix'], \array_column($steps, 'flag'));
        self::assertStringContainsString('URL', $steps[0]['effect']);
    }

    public function testShippedEntriesAndUrlDeclarationsShareTheOneFlag(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => 'declared as a URL', 'lifts' => '--fix']]];

        $steps = Report::nextSteps(['merged' => 2], $wrote);

        self::assertSame(['--fix'], \array_column($steps, 'flag'));
        self::assertStringContainsString('2', $steps[0]['effect']);
        self::assertStringContainsString('URL', $steps[0]['effect']);
    }
}
