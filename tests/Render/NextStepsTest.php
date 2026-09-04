<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Outcomes;
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
        self::assertSame([Report::REROLL, ''], [$steps[0]['command'], $steps[0]['flag']]);
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
        self::assertSame([Report::REROLL, '--update'], [$steps[0]['command'], $steps[0]['flag']]);
        self::assertStringContainsString('3', $steps[0]['effect']);
    }

    public function testOneShippedPatchIsSpokenOfInTheSingular(): void
    {
        self::assertStringContainsString('the shipped entry', Report::nextSteps(['merged' => 1])[0]['effect']);
    }

    public function testBothFindingsAreOfferedWorstFirst(): void
    {
        $steps = Report::nextSteps(['merged' => 3, 'conflicts' => 4]);

        self::assertSame([Report::REROLL, Report::REROLL], \array_column($steps, 'command'));
        self::assertSame(['', '--update'], \array_column($steps, 'flag'));
    }

    public function testAZeroCountIsNotAFinding(): void
    {
        self::assertSame([], Report::nextSteps(['conflicts' => 0, 'merged' => 0]));
    }

    public function testEverySuggestionNamesTheCommandAndWhatItDoes(): void
    {
        foreach (Report::nextStepLines(['conflicts' => 4, 'merged' => 3]) as $line) {
            self::assertStringContainsString(Report::REROLL, $line);
        }
    }

    public function testTheFirstLineIsLabelledAndTheRestAreNot(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 4, 'merged' => 3]);

        self::assertCount(2, $lines);
        self::assertStringContainsString('Next:', $lines[0]);
        self::assertStringNotContainsString('Next:', $lines[1]);
    }

    public function testTheScopeOfTheRunSitsBetweenTheCommandAndTheFlag(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 1], '  ', null, ['--target 11.4.5', '--package webform']);

        self::assertStringContainsString('composer drupatch:reroll --target 11.4.5 --package webform', $lines[0]);
    }

    public function testEveryStepRepeatsTheScopeAndTheEffectsStillLineUp(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 4, 'merged' => 3], '  ', null, ['--target 11.4.5']);

        self::assertStringContainsString('drupatch:reroll --target 11.4.5 ', $lines[0]);
        self::assertStringContainsString('--target 11.4.5 --update', $lines[1]);
        self::assertSame(\strpos($lines[0], 'writes'), \strpos($lines[1], 'drops'));
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
        $wrote = ['written' => [['path' => 'patches/a.patch', 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => []]], 'refused' => []];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], Outcomes::fromWrite($wrote)));
    }

    public function testARunThatLeftAConflictFileIsOfferedTheFlagThatFinishesIt(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => []]], 'refused' => []];

        $steps = Report::nextSteps(['conflicts' => 1], Outcomes::fromWrite($wrote));

        self::assertSame([''], \array_column($steps, 'flag'));
        self::assertSame([Report::REROLL], \array_column($steps, 'command'));
        self::assertSame('sends the regions you decide in the conflict file', $steps[0]['effect']);
    }

    public function testSeveralConflictFilesAreCounted(): void
    {
        $wrote = ['written' => [
            ['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => []],
            ['path' => 'patches/b.patch', 'status' => 'clean', 'package' => 'drupal/b', 'title' => 'Fix b', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => []],
            ['path' => 'patches/c.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/c', 'title' => 'Fix c', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => []],
        ], 'refused' => []];

        self::assertSame('sends the regions you decide in the 2 conflict files', Report::nextSteps(['conflicts' => 3], Outcomes::fromWrite($wrote))[0]['effect']);
    }

    public function testTheConflictFileComesBeforeTheRefusal(): void
    {
        $wrote = [
            'written' => [['path' => 'patches/a.conflict.patch', 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => []]],
            'refused' => [['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]],
        ];

        self::assertSame(['', '--force'], \array_column(Report::nextSteps(['conflicts' => 2], Outcomes::fromWrite($wrote)), 'flag'));
    }

    public function testARunThatCouldNotReplaceAFileIsOfferedTheFlagThatLetsIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]]];

        $steps = Report::nextSteps(['conflicts' => 1], Outcomes::fromWrite($wrote));

        self::assertSame(['--force'], \array_column($steps, 'flag'));
        self::assertSame('replaces the file this run would not overwrite', $steps[0]['effect']);
    }

    public function testSeveralRefusalsAreCounted(): void
    {
        $wrote = ['written' => [], 'refused' => [
            ['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false],
            ['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNTRACKED, 'lifts' => '--force', 'shipped' => false],
        ]];

        self::assertStringContainsString('2', Report::nextSteps(['conflicts' => 2], Outcomes::fromWrite($wrote))[0]['effect']);
    }

    public function testARefusalNoFlagLiftsSuggestsNothing(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => 'the service built no re-roll for it', 'lifts' => '', 'shipped' => false]]];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], Outcomes::fromWrite($wrote)));
    }

    public function testAFixRunIsNotOfferedTheFixAgain(): void
    {
        $outcomes = Outcomes::fromWrite(['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]]]);
        $outcomes->recordFix([['action' => 'dropped', 'package' => 'drupal/b', 'title' => 'Fix b', 'path' => '']], 'composer.json');

        self::assertSame(['--force'], \array_column(Report::nextSteps(['merged' => 2, 'conflicts' => 1], $outcomes), 'flag'));
    }

    public function testAShippedEntryIsStillOfferedAfterAWrite(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.patch', 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => []]], 'refused' => []];

        self::assertSame(['--update'], \array_column(Report::nextSteps(['merged' => 2, 'conflicts' => 1], Outcomes::fromWrite($wrote)), 'flag'));
    }

    public function testAUrlDeclarationIsOfferedTheFlagThatAdoptsIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => 'declared as a URL', 'lifts' => '--update', 'shipped' => false]]];

        $steps = Report::nextSteps([], Outcomes::fromWrite($wrote));

        self::assertSame(['--update'], \array_column($steps, 'flag'));
        self::assertStringContainsString('URL', $steps[0]['effect']);
    }

    public function testShippedEntriesAndUrlDeclarationsShareTheOneFlag(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => 'declared as a URL', 'lifts' => '--update', 'shipped' => false]]];

        $steps = Report::nextSteps(['merged' => 2], Outcomes::fromWrite($wrote));

        self::assertSame(['--update'], \array_column($steps, 'flag'));
        self::assertStringContainsString('2', $steps[0]['effect']);
        self::assertStringContainsString('URL', $steps[0]['effect']);
    }
}
