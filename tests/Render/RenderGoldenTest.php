<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\Golden;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Every report the plugin prints, captured whole, with the sections it is made of by name and in order.
 *
 * These call the render entry points, so the lines carry their colour tags
 * and no terminal, network, git or child composer is involved. The width is
 * named rather than measured.
 *
 * @phpstan-import-type Section from Report
 * @phpstan-import-type Write from Report
 */
class RenderGoldenTest extends TestCase
{
    use Golden;
    use PlanFactory;

    private const WIDTH = 100;

    public function testCheckClean(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 2, 'merged' => 1],
            'patches' => [
                $this->row(['title' => 'Fix the alter hook', 'source' => 'patches/webform/alter.patch']),
                $this->row(['title' => 'Second thoughts', 'source' => 'patches/webform/second.patch']),
                $this->row(['package' => 'drupal/core', 'project' => 'drupal', 'version' => '11.4.5', 'title' => 'Menu cache', 'source' => 'patches/core/menu.patch', 'verdict' => 'merged']),
            ],
        ]);

        self::assertReport('check-clean', ['headline', 'package', 'package', 'tally', 'next'], self::whole($plan, null));
    }

    public function testCheckConflictsWithShippedHunks(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1, 'applies' => 1],
            'patches' => [
                $this->row(['title' => 'Fix the alter hook', 'source' => 'patches/webform/alter.patch', 'verdict' => 'conflicts', 'result' => [
                    'hunks_failed' => [
                        ['file' => 'webform.module', 'line' => 165, 'reason' => 'patch failed'],
                        ['file' => 'src/Entity/Webform.php', 'line' => 42, 'reason' => 'patch failed'],
                    ],
                    'hunks_shipped' => [['file' => 'webform.module', 'line' => 6, 'reason' => 'this hunk is already in the release']],
                ]]),
                $this->row(['title' => 'Second thoughts', 'source' => 'patches/webform/second.patch']),
            ],
        ]);

        self::assertReport('check-conflicts-shipped', ['headline', 'package', 'tally', 'next'], self::whole($plan, null));
    }

    // 2.x applies with `git apply` alone, so a patch only a lenient apply
    // took stops applying there.
    public function testCheckRefusedByTwo(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row([
                'title' => 'Fix the alter hook',
                'source' => 'patches/webform/alter.patch',
                'result' => ['fuzzy' => true, 'strict_refused' => 'the patch carries the packaging block as context'],
            ])],
        ], Manager::ofVersion('2.0.0'));

        self::assertReport('check-refused-by-two', ['headline', 'package', 'tally'], self::whole($plan, null));
    }

    // 1.x applies loosely, so the same patch still applies and the row
    // carries the service's own reason.
    public function testCheckStrictRefusalOnOne(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row([
                'title' => 'Fix the alter hook',
                'source' => 'patches/webform/alter.patch',
                'result' => ['fuzzy' => true, 'strict_refused' => 'the patch carries the packaging block as context'],
            ])],
        ], Manager::ofVersion('1.7.3'));

        self::assertReport('check-strict-refusal-on-one', ['headline', 'package', 'tally'], self::whole($plan, null));
    }

    public function testCheckJudgedNothing(): void
    {
        $plan = $this->planFrom(['counts' => [], 'patches' => []]);
        $coverage = ['checked' => 0, 'skipped' => [
            ['package' => 'acme/pagebuilder', 'title' => 'Page builder fix', 'reason' => 'not a drupal.org project'],
        ], 'unsent' => [], 'versions' => ['acme/pagebuilder' => '7.6.1']];

        self::assertReport('check-judged-nothing', ['headline', 'unjudged', 'tally'], Report::check($plan, $coverage, null, null, self::WIDTH, []));
    }

    public function testCheckSkippedPackages(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row(['title' => 'Fix the alter hook', 'source' => 'patches/webform/alter.patch'])],
        ]);
        $coverage = ['checked' => 1, 'skipped' => [
            ['package' => 'acme/pagebuilder', 'title' => 'Page builder fix', 'reason' => 'not a drupal.org project'],
        ], 'unsent' => [
            ['package' => 'drupal/domain', 'title' => 'Access fix', 'source' => 'https://example.test/d.patch', 'reason' => 'the host answered 404'],
        ], 'versions' => ['drupal/webform' => '6.2.9', 'acme/pagebuilder' => '7.6.1']];

        self::assertReport('check-skipped-packages', ['headline', 'unjudged', 'package', 'tally'], Report::check($plan, $coverage, null, null, self::WIDTH, []));
    }

    public function testCheckBrokenSyntax(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row(['title' => 'Fix the alter hook', 'source' => 'patches/webform/alter.patch', 'result' => [
                'failure_mode' => 'broken syntax',
                'syntax_errors' => [
                    'src/A.php: Cannot use A\\C as C because the name is already in use on line 118',
                    'webform.services.yml: mapping values are not allowed in this context on line 12',
                ],
            ]])],
        ]);

        self::assertReport('check-broken-syntax', ['headline', 'package', 'tally'], self::whole($plan, null));
    }

    public function testRerollWroteFiles(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1, 'applies' => 1],
            'patches' => [
                $this->row(['title' => 'Fix a', 'source' => 'patches/webform/a.patch', 'verdict' => 'conflicts']),
                $this->row(['title' => 'Fix b', 'source' => 'patches/webform/b.patch']),
            ],
        ]);
        $outcomes = [
            'written' => [$this->writtenFile('patches/webform/a.patch', 'clean', 'drupal/webform', 'Fix a')],
            'refused' => [],
        ];

        self::assertReport('reroll-wrote-files', ['headline', 'tally', 're-rolled'], self::whole($plan, $outcomes));
    }

    public function testRerollLeftOpenRegions(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 2],
            'patches' => [
                $this->row(['title' => 'Fix a', 'source' => 'patches/webform/a.patch', 'verdict' => 'conflicts']),
                $this->row(['title' => 'Fix b', 'source' => 'patches/webform/b.patch', 'verdict' => 'conflicts']),
            ],
        ]);
        $outcomes = [
            'written' => [$this->writtenFile('patches/webform/a.conflict.patch', 'conflicts', 'drupal/webform', 'Fix a', true, 2, 'src/Entity/Webform.php')],
            'refused' => [['package' => 'drupal/webform', 'title' => 'Fix b', 'path' => 'patches/webform/b.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force', 'shipped' => false]],
        ];

        self::assertReport('reroll-open-regions', ['headline', 'tally', 'conflicted', 'refused', 'next'], self::whole($plan, $outcomes));
    }

    public function testRerollRefusedAUrlPatch(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1],
            'patches' => [$this->row(['title' => 'Fix a', 'source' => 'https://example.test/a.patch', 'verdict' => 'conflicts'])],
        ]);
        $outcomes = [
            'written' => [],
            'refused' => [['package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'https://example.test/a.patch', 'reason' => PatchFiles::URL_DECLARED, 'lifts' => '', 'shipped' => false]],
        ];

        self::assertReport('reroll-refused-url', ['headline', 'tally', 'refused', 'next'], self::whole($plan, $outcomes));
    }

    public function testHookReport(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1, 'merged' => 1, 'applies' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['title' => 'Fix a', 'verdict' => 'conflicts']),
                $this->row(['title' => 'Fix b', 'verdict' => 'applies']),
                $this->row(['package' => 'drupal/core', 'version' => '11.4.5', 'title' => 'Menu cache', 'verdict' => 'merged']),
            ],
        ]);

        self::assertReport('hook-report', ['hook'], Report::hook($plan));
    }

    public function testPinReport(): void
    {
        $row = ['package' => 'drupal/webform', 'title' => '3521733: bfcache', 'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch', 'path' => 'patch/webform/mr940.diff'];
        $lists = [
            'vendored' => [$row],
            'kept' => [['package' => 'drupal/core', 'title' => 'Menu cache', 'source' => 'https://example.test/menu.patch', 'path' => 'patch/drupal/menu.diff']],
            'moved' => [],
            'refused' => [['package' => 'drupal/domain', 'title' => 'Access fix', 'source' => 'https://example.test/d.patch', 'reason' => 'the host answered 404', 'lifts' => '']],
        ];

        self::assertReport('pin-report', ['headline', 'copied', 'held', 'not-copied', 'rewrite'], Report::pin($lists, 'composer.json', 1, 0, Manager::ofVersion('1.7.3')));
    }

    public function testAddReport(): void
    {
        $outcome = [
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

        self::assertReport('add-report', ['issue', 'copy', 'declared'], Report::add($outcome));
    }

    public function testUpgradeDryRun(): void
    {
        $move = self::move([
            'refused' => [
                ['package' => 'drupal/webform', 'number' => 1, 'outcome' => 'clean', 'regions' => 0, 'why' => ''],
                ['package' => 'drupal/core', 'number' => 2, 'outcome' => 'open', 'regions' => 3, 'why' => ''],
                ['package' => 'drupal/pathauto', 'number' => 1, 'outcome' => 'none', 'regions' => 0, 'why' => 'no release takes this patch'],
                ['package' => 'drupal/metatag', 'number' => 1, 'outcome' => 'shipped', 'regions' => 0, 'why' => ''],
            ],
            'depths' => [['package' => 'drupal/webform', 'number' => 1, 'depth' => 2]],
        ]);

        self::assertReport('upgrade-dry-run', ['move', 'refusals', 'depths', 'layout', 'dry-run'], Report::upgrade($move));
    }

    public function testUpgradeReal(): void
    {
        $move = self::move([
            'wrote' => [
                'vendored' => 2,
                'rerolled' => 1,
                'forcible' => 0,
                'files' => ['patches.json', 'composer.json'],
                'open' => [],
                'refused' => [],
            ],
            'renamed' => ['patches-ignore' => 'ignore-dependency-patches'],
            'depths' => [['package' => 'drupal/core', 'number' => 1, 'depth' => 1]],
        ]);

        self::assertReport('upgrade-real', ['move', 'refusals', 'depths', 'layout', 'settings', 'wrote', 'moved'], [...Report::upgrade($move), ...Report::moved()]);
    }

    /**
     * @param list<string>  $names    the sections, by name and in order
     * @param list<Section> $sections
     */
    private static function assertReport(string $golden, array $names, array $sections): void
    {
        self::assertSame($names, \array_column($sections, 'name'), $golden);
        self::assertGolden($golden, Report::print($sections));
    }

    /**
     * @param Write|null $write
     *
     * @return list<Section>
     */
    private static function whole(Plan $plan, ?array $write): array
    {
        return Report::check($plan, ['checked' => \count($plan->rows()), 'skipped' => [], 'unsent' => [], 'versions' => []], $write, null, self::WIDTH, []);
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
