<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * The sections each report is made of, by name and in order, for the runs no golden file captures.
 *
 * @phpstan-import-type Section from Report
 */
class SectionsTest extends TestCase
{
    use PlanFactory;

    public function testAPlainRunWarnsAboutWhatTheTableCannotShow(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1],
            'warnings' => ['the request was trimmed'],
            'patches' => [$this->row(['verdict' => 'conflicts', 'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch'])],
        ]);

        self::assertSame(
            ['headline', 'warnings', 'package', 'tally', 'unpinned', 'next'],
            self::names(Report::check($plan, ['checked' => 1, 'skipped' => [], 'unsent' => [], 'versions' => []], null, null, 100, [])),
        );
    }

    public function testAReRollRunPrintsEveryWriteSectionInOrder(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 2, 'merged' => 2], 'patches' => [
            $this->row(['title' => 'Kept', 'verdict' => 'merged']),
            $this->row(['title' => 'Gone', 'verdict' => 'merged']),
            $this->row(['title' => 'Fix a', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Fix b', 'verdict' => 'conflicts']),
        ]]);
        $write = [
            'written' => [
                $this->writtenFile('patches/a.patch', 'clean', 'drupal/webform', 'Fix a'),
                $this->writtenFile('patches/b.conflict.patch', 'conflicts', 'drupal/webform', 'Fix b', false, 1),
            ],
            'refused' => [
                ['package' => 'drupal/webform', 'title' => 'Kept', 'path' => 'patches/kept.patch', 'reason' => 'the merge changes nothing', 'lifts' => '', 'shipped' => true],
                ['package' => 'drupal/webform', 'title' => 'Gone', 'path' => 'patches/gone.patch', 'reason' => 'the merge changes nothing', 'lifts' => '', 'shipped' => true],
                ['package' => 'drupal/webform', 'title' => 'Fix c', 'path' => 'patches/c.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false],
            ],
        ];
        $rewrite = ['changes' => [['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Gone', 'path' => '', 'provenance' => []]], 'declaration' => 'composer.json'];

        self::assertSame(
            ['headline', 'tally', 'shipped', 'dropped', 're-rolled', 'conflicted', 'refused', 'rewrite', 'next'],
            self::names(Report::check($plan, ['checked' => 4, 'skipped' => [], 'unsent' => [], 'versions' => []], $write, $rewrite, 100, [])),
        );
    }

    public function testAPinRunThatFoundAMovedRequestSaysHowToTakeIt(): void
    {
        $row = ['package' => 'drupal/webform', 'title' => 'bfcache', 'path' => 'patch/webform/mr940.diff'];

        self::assertSame(['headline', 'moved', 'unpinned'], self::names(Report::pin(['vendored' => [], 'kept' => [], 'moved' => [$row], 'refused' => []], '', 0, 1, Manager::ofVersion('1.7.3'))));
    }

    public function testAnAddRunThatStoppedPrintsTheReasonAlone(): void
    {
        self::assertSame(['stopped'], self::names(Report::add(self::outcome(['error' => 'this site does not install drupal/token']))));
    }

    public function testAnAddDryRunStopsAfterTheCopy(): void
    {
        self::assertSame(['issue', 'copy', 'dry-run'], self::names(Report::add(self::outcome(['dryRun' => true]))));
    }

    public function testAnAddRunThatChoseAndDeclaredNothingSaysWhy(): void
    {
        $candidate = ['iid' => '940', 'title' => 'Fix the cache', 'target' => '6.2.x', 'draft' => false, 'state' => 'opened', 'updated' => '2026-09-09T14:27:14Z'];

        self::assertSame(['count', 'candidates'], self::names(Report::candidates([$candidate], '3521733')));
        self::assertSame(['issue', 'took', 'copy', 'undeclared'], self::names(Report::add(self::outcome(['candidates' => [$candidate], 'verdict' => 'merged', 'declared' => false]))));
    }

    public function testAnUpgradeThatStoppedPrintsTheReasonAlone(): void
    {
        self::assertSame(['stopped'], self::names(Report::upgrade(self::move(['error' => 'this site already runs cweagans/composer-patches 2.x']))));
    }

    public function testAResumedUpgradeWritesNothing(): void
    {
        self::assertSame(['move', 'resumed'], self::names(Report::upgrade(self::move(['resumed' => true]))));
    }

    public function testAnUpgradeThatLeftRegionsOpenSaysHowToDecideThem(): void
    {
        $wrote = ['vendored' => 0, 'rerolled' => 0, 'forcible' => 0, 'files' => [], 'open' => [['path' => 'patches/a.conflict.patch', 'regions' => 2]], 'refused' => []];

        self::assertSame(['move', 'refusals', 'layout', 'open', 'decide'], self::names(Report::upgrade(self::move(['wrote' => $wrote]))));
    }

    public function testTheManagerCommandsSayWhereTheyStopped(): void
    {
        self::assertSame(['moved'], self::names(Report::moved()));
        self::assertSame([], Report::unfinished(['ran' => ['patches-relock'], 'error' => '', 'left' => []]));
        self::assertSame(['unfinished'], self::names(Report::unfinished(['ran' => [], 'error' => 'composer patches-relock exited 1', 'left' => ['patches-relock']])));
    }

    public function testThePluginSaysWhatLeavesTheSiteAndWhichLineToBeOn(): void
    {
        self::assertSame(['notice', 'notice'], self::names(Report::notice()));
        self::assertSame(['move', 'move', 'move'], self::names(Report::movePrompt(Manager::ofVersion('1.7.3'))));
        self::assertSame([], Report::movePrompt(Manager::ofVersion('2.0.0')));
        self::assertSame(['unpinned'], self::names(Report::unpinnedWarning(Manager::ofVersion('1.7.3'), 2)));
        self::assertSame([], Report::unpinnedWarning(Manager::ofVersion('1.7.3'), 0));
        self::assertSame([], Report::unpinnedWarning(Manager::ofVersion('2.0.0'), 2));
    }

    /**
     * @param list<Section> $sections
     *
     * @return list<string>
     */
    private static function names(array $sections): array
    {
        return \array_column($sections, 'name');
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function outcome(array $over = []): array
    {
        return $over + [
            'issue' => '3521733',
            'title' => '3521733: Fix the back/forward cache',
            'package' => 'drupal/webform',
            'path' => 'patch/webform/mr940.diff',
            'verdict' => 'applies',
            'reason' => '',
            'wrote' => 'patch/webform/mr940.diff',
            'regions' => [],
            'declared' => true,
            'exit' => 0,
            'candidates' => [],
            'chosen' => 0,
            'error' => '',
            'dryRun' => false,
        ];
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function move(array $over = []): array
    {
        return $over + [
            'from' => '1.7.3',
            'refused' => [],
            'depths' => [],
            'file' => 'patches.json',
            'renamed' => [],
            'dropped' => [],
            'wrote' => null,
            'error' => '',
            'resumed' => false,
            'unchecked' => 0,
        ];
    }
}
