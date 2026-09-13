<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * @phpstan-import-type Write from Report
 */
class NextStepsTest extends TestCase
{
    public function testAPlanWithNothingToClearSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps(['applies' => 4], null, null));
        self::assertSame([], Report::nextStepLines(['applies' => 4], null, null, []));
    }

    public function testAnEmptyPlanSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps([], null, null));
    }

    public function testAnUnclearPlanSuggestsNothing(): void
    {
        self::assertSame([], Report::nextSteps(['unknown' => 3], null, null), 'no flag clears a verdict the service could not reach');
    }

    public function testARerollIsOfferedTheFlagThatWritesIt(): void
    {
        $steps = Report::nextSteps(['conflicts' => 4], null, null);

        self::assertCount(1, $steps);
        self::assertSame([Report::REROLL, ''], [$steps[0]['command'], $steps[0]['flag']]);
        self::assertStringContainsString('4', $steps[0]['effect']);
    }

    public function testOneRerollIsSpokenOfInTheSingular(): void
    {
        self::assertSame('writes the re-roll', Report::nextSteps(['conflicts' => 1], null, null)[0]['effect']);
    }

    public function testAShippedPatchIsOfferedTheRunThatDropsIt(): void
    {
        $steps = Report::nextSteps(['merged' => 3], null, null);

        self::assertCount(1, $steps);
        self::assertSame([Report::REROLL, ''], [$steps[0]['command'], $steps[0]['flag']]);
        self::assertStringContainsString('3', $steps[0]['effect']);
    }

    public function testOneShippedPatchIsSpokenOfInTheSingular(): void
    {
        self::assertStringContainsString('the shipped entry', Report::nextSteps(['merged' => 1], null, null)[0]['effect']);
    }

    // One run writes the re-rolls and drops what shipped, so both findings
    // are answered by one line.
    public function testBothFindingsAreOneRun(): void
    {
        $steps = Report::nextSteps(['merged' => 3, 'conflicts' => 4], null, null);

        self::assertCount(1, $steps);
        self::assertSame([Report::REROLL, ''], [$steps[0]['command'], $steps[0]['flag']]);
        self::assertSame('writes the 4 re-rolls and drops the 3 shipped entries from composer.json', $steps[0]['effect']);
    }

    public function testAZeroCountIsNotAFinding(): void
    {
        self::assertSame([], Report::nextSteps(['conflicts' => 0, 'merged' => 0], null, null));
    }

    public function testEverySuggestionNamesTheCommandAndWhatItDoes(): void
    {
        foreach (Report::nextStepLines(['conflicts' => 4, 'merged' => 3], null, null, []) as $line) {
            self::assertStringContainsString(Report::REROLL, $line);
        }
    }

    /**
     * A write run that left a conflict file open and refused a URL declaration, so its footer holds two commands.
     *
     * @return Write
     */
    private static function twoSteps(): array
    {
        return [
            'written' => [['path' => 'patches/a.conflict.patch', 'provenance' => [], 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'a.php', 'region' => 0]], 'removed' => [], 'dropped' => [], 'from' => '']],
            'refused' => [['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'https://example.test/b.patch', 'reason' => PatchFiles::URL_DECLARED, 'lifts' => '', 'shipped' => false]],
        ];
    }

    public function testTheFirstLineIsLabelledAndTheRestAreNot(): void
    {
        $lines = Report::nextStepLines([], self::twoSteps(), null, []);

        self::assertCount(2, $lines);
        self::assertStringContainsString('Next:', $lines[0]);
        self::assertStringNotContainsString('Next:', $lines[1]);
    }

    public function testTheScopeOfTheRunSitsBetweenTheCommandAndTheFlag(): void
    {
        $lines = Report::nextStepLines(['conflicts' => 1], null, null, ['--target 11.4.5', '--package webform']);

        self::assertStringContainsString('composer drupatch:reroll --target 11.4.5 --package webform', $lines[0]);
    }

    public function testEveryStepRepeatsTheScopeAndTheEffectsStillLineUp(): void
    {
        $lines = Report::nextStepLines([], self::twoSteps(), null, ['--target 11.4.5']);

        self::assertStringContainsString('drupatch:reroll --target 11.4.5 ', $lines[0]);
        self::assertStringContainsString('drupatch:pin --target 11.4.5 ', $lines[1]);
        self::assertSame(\strpos($lines[0], 'sends'), \strpos($lines[1], 'copies'));
    }

    public function testTheCommandsLineUp(): void
    {
        $lines = Report::nextStepLines([], self::twoSteps(), null, []);

        self::assertSame(
            \strpos($lines[0], 'composer '),
            \strpos($lines[1], 'composer '),
            'the command column starts at the same offset on every line',
        );
    }

    public function testTheEffectsLineUp(): void
    {
        $lines = Report::nextStepLines([], self::twoSteps(), null, []);

        self::assertSame(
            \strpos($lines[0], 'sends'),
            \strpos($lines[1], 'copies'),
            'the effect column starts at the same offset on every line',
        );
    }

    public function testARunThatWroteEveryRerollSuggestsNoWriteStep(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.patch', 'provenance' => [], 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => [], 'dropped' => [], 'from' => '']], 'refused' => []];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], $wrote, null));
    }

    public function testARunThatLeftAConflictFileIsOfferedTheFlagThatFinishesIt(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.conflict.patch', 'provenance' => [], 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => [], 'dropped' => [], 'from' => '']], 'refused' => []];

        $steps = Report::nextSteps(['conflicts' => 1], $wrote, null);

        self::assertSame([''], \array_column($steps, 'flag'));
        self::assertSame([Report::REROLL], \array_column($steps, 'command'));
        self::assertSame('sends the regions you decide in the conflict file', $steps[0]['effect']);
    }

    public function testSeveralConflictFilesAreCounted(): void
    {
        $wrote = ['written' => [
            ['path' => 'patches/a.conflict.patch', 'provenance' => [], 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => [], 'dropped' => [], 'from' => ''],
            ['path' => 'patches/b.patch', 'provenance' => [], 'status' => 'clean', 'package' => 'drupal/b', 'title' => 'Fix b', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => [], 'dropped' => [], 'from' => ''],
            ['path' => 'patches/c.conflict.patch', 'provenance' => [], 'status' => 'conflicts', 'package' => 'drupal/c', 'title' => 'Fix c', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => [], 'dropped' => [], 'from' => ''],
        ], 'refused' => []];

        self::assertSame('sends the regions you decide in the 2 conflict files', Report::nextSteps(['conflicts' => 3], $wrote, null)[0]['effect']);
    }

    public function testTheConflictFileComesBeforeTheRefusal(): void
    {
        $wrote = [
            'written' => [['path' => 'patches/a.conflict.patch', 'provenance' => [], 'status' => 'conflicts', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => false, 'unioned' => [], 'regions' => 1, 'open' => [['file' => 'src/A.php', 'region' => 0]], 'removed' => [], 'dropped' => [], 'from' => '']],
            'refused' => [['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]],
        ];

        self::assertSame(['', '--force'], \array_column(Report::nextSteps(['conflicts' => 2], $wrote, null), 'flag'));
    }

    public function testARunThatCouldNotReplaceAFileIsOfferedTheFlagThatLetsIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]]];

        $steps = Report::nextSteps(['conflicts' => 1], $wrote, null);

        self::assertSame(['--force'], \array_column($steps, 'flag'));
        self::assertSame('replaces the file this run would not overwrite', $steps[0]['effect']);
    }

    public function testSeveralRefusalsAreCounted(): void
    {
        $wrote = ['written' => [], 'refused' => [
            ['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false],
            ['package' => 'drupal/b', 'title' => 'Fix b', 'path' => 'patches/b.patch', 'reason' => WorkingTree::UNTRACKED, 'lifts' => '--force', 'shipped' => false],
        ]];

        self::assertStringContainsString('2', Report::nextSteps(['conflicts' => 2], $wrote, null)[0]['effect']);
    }

    public function testARefusalNoFlagLiftsSuggestsNothing(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => 'the service built no re-roll for it', 'lifts' => '', 'shipped' => false]]];

        self::assertSame([], Report::nextSteps(['conflicts' => 1], $wrote, null));
    }

    public function testAFixRunIsNotOfferedTheFixAgain(): void
    {
        $outcomes = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]]];
        $rewrite = ['changes' => [['action' => 'dropped', 'package' => 'drupal/b', 'title' => 'Fix b', 'path' => '', 'provenance' => []]], 'declaration' => 'composer.json'];

        self::assertSame(['--force'], \array_column(Report::nextSteps(['merged' => 2, 'conflicts' => 1], $outcomes, $rewrite), 'flag'));
    }

    public function testAShippedEntryIsStillOfferedAfterAWriteThatDidNotRewrite(): void
    {
        $wrote = ['written' => [['path' => 'patches/a.patch', 'provenance' => [], 'status' => 'clean', 'package' => 'drupal/a', 'title' => 'Fix a', 'verified' => true, 'unioned' => [], 'regions' => 0, 'open' => [], 'removed' => [], 'dropped' => [], 'from' => '']], 'refused' => []];

        $steps = Report::nextSteps(['merged' => 2, 'conflicts' => 1], $wrote, null);

        self::assertSame([Report::REROLL], \array_column($steps, 'command'));
        self::assertStringContainsString('drops the 2 shipped entries', $steps[0]['effect']);
    }

    public function testAUrlDeclarationIsSentToTheCommandThatCopiesIt(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => PatchFiles::URL_DECLARED, 'lifts' => '', 'shipped' => false]]];

        $steps = Report::nextSteps([], $wrote, null);

        self::assertSame([Report::PIN], \array_column($steps, 'command'));
        self::assertSame('copies the patch declared as a URL into the site', $steps[0]['effect']);
    }

    // A shipped entry and a URL declaration are two commands now, in the
    // order a person runs them.
    public function testShippedEntriesAndUrlDeclarationsAreTwoRuns(): void
    {
        $wrote = ['written' => [], 'refused' => [['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => PatchFiles::URL_DECLARED, 'lifts' => '', 'shipped' => false]]];

        $steps = Report::nextSteps(['merged' => 2], $wrote, null);

        self::assertSame([Report::REROLL, Report::PIN], \array_column($steps, 'command'));
        self::assertStringContainsString('2', $steps[0]['effect']);
    }
}
