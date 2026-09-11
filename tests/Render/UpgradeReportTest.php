<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\UpgradeReport;

/**
 * @phpstan-import-type MoveReport from \TresBienTech\Drupatch\Command\UpgradeCommand
 */
#[CoversClass(UpgradeReport::class)]
class UpgradeReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return MoveReport
     */
    private static function move(array $over = []): array
    {
        /** @var MoveReport $out */
        $out = $over + [
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

        return $out;
    }

    public function testItNamesTheReleaseItMovesFromAndWritesNothing(): void
    {
        $lines = UpgradeReport::lines(self::move());

        self::assertContains('  cweagans/composer-patches 1.7.3 -> ^2', $lines);
        self::assertSame([
            '  nothing written (--dry-run)',
            '  a real run finishes with:',
            '    composer update cweagans/composer-patches --with-dependencies',
            '    composer patches-relock',
            '    composer patches-repatch',
        ], \array_slice($lines, -5));
    }

    // An earlier run moved composer.json, so there is no cost to show.
    public function testAResumedRunSaysItWritesNothingAndLeavesTheCommandsToTheRun(): void
    {
        $wrote = ['vendored' => 0, 'rerolled' => 0, 'forcible' => 0, 'files' => [], 'open' => [], 'refused' => []];

        self::assertSame(
            ['', '  cweagans/composer-patches 1.7.3 -> ^2', '', '  '.UpgradeReport::RESUMED],
            UpgradeReport::lines(self::move(['resumed' => true, 'wrote' => $wrote]))
        );
        self::assertSame(
            ['  '.UpgradeReport::RESUMED, '  a real run finishes with:'],
            \array_slice(UpgradeReport::lines(self::move(['resumed' => true])), 3, 2)
        );
    }

    public function testAMoveWhoseCommandsFinishedClosesOnTheLineItReached(): void
    {
        self::assertSame(['', '  moved to cweagans/composer-patches 2.x'], UpgradeReport::moved());
    }

    /**
     * One strict refusal, with what its re-roll does.
     *
     * @param 'clean'|'open'|'none'|'shipped' $outcome
     *
     * @return array{package: string, number: int, outcome: 'clean'|'open'|'none'|'shipped', regions: int, why: string}
     */
    private static function refusal(string $package, int $number, string $outcome, int $regions = 0, string $why = ''): array
    {
        return ['package' => $package, 'number' => $number, 'outcome' => $outcome, 'regions' => $regions, 'why' => $why];
    }

    // A strict refusal is the work the move does, so each row says what
    // its re-roll comes to rather than why git apply refused it.
    public function testEachStrictRefusalSaysWhatItsRerollDoes(): void
    {
        $lines = UpgradeReport::lines(self::move(['refused' => [
            self::refusal('drupal/webform', 1, 'clean'),
            self::refusal('drupal/core', 2, 'open', 1),
            self::refusal('drupal/token', 1, 'open', 3),
            self::refusal('drupal/pathauto', 2, 'none', why: 'no release takes this patch'),
            self::refusal('drupal/metatag', 1, 'shipped'),
        ]]));

        self::assertContains('  5 patches need a re-roll before 2.x applies them:', $lines);
        self::assertContains('    drupal/webform    #1  re-rolls clean', $lines);
        self::assertContains('    drupal/core       #2  leaves 1 region to decide', $lines);
        self::assertContains('    drupal/token      #1  leaves 3 regions to decide', $lines);
        self::assertContains('    drupal/pathauto   #2  no re-roll: no release takes this patch', $lines);
        self::assertContains('    drupal/metatag    #1  already in the release', $lines);
    }

    public function testOnePatchToRerollIsCountedInTheSingular(): void
    {
        $lines = UpgradeReport::lines(self::move(['refused' => [self::refusal('drupal/webform', 1, 'clean')]]));

        self::assertContains('  1 patch needs a re-roll before 2.x applies it:', $lines);
    }

    public function testADryRunSaysARealRunStopsOnARowThatDoesNotRerollClean(): void
    {
        $lines = UpgradeReport::lines(self::move(['refused' => [
            self::refusal('drupal/webform', 1, 'clean'),
            self::refusal('drupal/core', 2, 'open', 1),
        ]]));

        self::assertContains('  '.UpgradeReport::STOPS, $lines);
    }

    public function testADryRunWhoseRerollsAllLandSaysNothingAboutStopping(): void
    {
        $lines = UpgradeReport::lines(self::move(['refused' => [
            self::refusal('drupal/webform', 1, 'clean'),
            self::refusal('drupal/metatag', 1, 'shipped'),
        ]]));

        self::assertNotContains('  '.UpgradeReport::STOPS, $lines);
    }

    // The re-roll replaces the fuzzy apply, so no line sends the site to a
    // plugin that brings fuzz back.
    public function testNoLineNamesAPatcherThatBringsFuzzBack(): void
    {
        $lines = UpgradeReport::lines(self::move(['refused' => [
            self::refusal('drupal/pathauto', 2, 'none', why: 'no release takes this patch'),
        ]]));

        foreach ($lines as $line) {
            self::assertStringNotContainsString('patch-patcher', $line);
        }
    }

    // A patch the service never judged has no verdict to promise anything about.
    public function testAPatchWithNoVerdictIsCountedApart(): void
    {
        $lines = UpgradeReport::lines(self::move(['unchecked' => 2]));

        self::assertContains('  the move stops no checked patch from applying', $lines);
        self::assertContains('  2 patches were not checked, so this run cannot say whether 2.x applies them', $lines);
    }

    public function testASiteWhosePatchesAllApplyIsToldSo(): void
    {
        $lines = UpgradeReport::lines(self::move());

        self::assertContains('  the move stops no patch from applying', $lines);
    }

    public function testADepthOtherThanThePackageDefaultIsNamedWithItsLevel(): void
    {
        $lines = UpgradeReport::lines(self::move(['depths' => [
            ['package' => 'drupal/core', 'number' => 3, 'depth' => 0],
        ]]));

        self::assertContains('  1 patch applies at a depth other than its package default:', $lines);
        self::assertContains('    drupal/core       #3  -p0', $lines);
    }

    public function testNoDepthBlockPrintsWhenEveryPatchSitsAtItsDefault(): void
    {
        foreach (UpgradeReport::lines(self::move()) as $line) {
            self::assertStringNotContainsString('depth other than', $line);
        }
    }

    // An edit to composer.json invalidates composer.lock's content hash, so
    // the declarations move out of it once.
    public function testTheLayoutMoveNamesTheFileAndTheKeyThatFindsIt(): void
    {
        $lines = UpgradeReport::lines(self::move());

        self::assertContains('  layout:', $lines);
        self::assertContains('    extra.patches            -> patches.json', $lines);
        self::assertContains('                                extra.composer-patches.patches-file', $lines);
    }

    public function testARenamedSettingCarriesItsNewNameAndADroppedOneItsReason(): void
    {
        $lines = UpgradeReport::lines(self::move([
            'renamed' => ['patches-ignore' => 'ignore-dependency-patches'],
            'dropped' => ['patchLevel' => 'a measured depth replaces it'],
        ]));

        self::assertContains('  settings:', $lines);
        self::assertContains('    extra.patches-ignore     -> extra.composer-patches.ignore-dependency-patches', $lines);
        self::assertContains('    extra.patchLevel         dropped, a measured depth replaces it', $lines);
    }

    public function testASiteThatMovesNoSettingPrintsNoSettingBlock(): void
    {
        foreach (UpgradeReport::lines(self::move()) as $line) {
            self::assertStringNotContainsString('settings:', $line);
        }
    }

    public function testARefusalPrintsTheReasonAndNothingElse(): void
    {
        self::assertSame(
            ['', '  <error>this site already runs cweagans/composer-patches 2.x</error>'],
            UpgradeReport::lines(self::move(['error' => 'this site already runs cweagans/composer-patches 2.x']))
        );
    }

    // A refusal `--force` lifts is worth a command, because the move cannot
    // be run twice and the site is left with a patch that will not apply.
    public function testARefusalForceLiftsNamesTheCommandThatClearsIt(): void
    {
        $lines = UpgradeReport::lines(self::move(['wrote' => [
            'vendored' => 1,
            'rerolled' => 0,
            'forcible' => 1,
            'files' => ['patches.json', 'composer.json'],
            'open' => [],
            'refused' => [['title' => 'Draft translations', 'reason' => 'it has never been committed']],
        ]]));

        self::assertContains('  run `composer drupatch:reroll --force` to replace the file this run would not overwrite', $lines);
    }

    public function testARunWithNothingToForceNamesNoCommand(): void
    {
        $lines = UpgradeReport::lines(self::move(['wrote' => [
            'vendored' => 1,
            'rerolled' => 1,
            'forcible' => 0,
            'files' => ['patches.json', 'composer.json'],
            'open' => [],
            'refused' => [],
        ]]));

        foreach ($lines as $line) {
            self::assertStringNotContainsString('--force', $line);
        }
    }

    // Both documents wait on every patch landing, so a stopped run wrote
    // neither and the requirement still names 1.x.
    public function testARunThatWroteNothingSaysTheSiteStaysOn1x(): void
    {
        $lines = UpgradeReport::lines(self::move(['wrote' => [
            'vendored' => 0,
            'rerolled' => 0,
            'forcible' => 0,
            'files' => [],
            'open' => [],
            'refused' => [],
        ]]));

        self::assertContains('  '.UpgradeReport::STAYED, $lines);
        self::assertNotContains('  wrote ', $lines);
    }

    public function testTheOpenRegionLineNamesTheRerollCommandOnce(): void
    {
        $lines = UpgradeReport::lines(self::move(['wrote' => [
            'vendored' => 0,
            'rerolled' => 0,
            'forcible' => 0,
            'files' => [],
            'open' => [['path' => 'patch/webform/mr940.conflict.patch', 'regions' => 2]],
            'refused' => [],
        ]]));

        self::assertContains('  decide them, run `composer drupatch:reroll`, then run this command again', $lines);
    }

    // The columns are the run's own, so a long package name pushes both
    // lists rather than pushing its own number out of line.
    public function testALongPackageNameWidensBothListsAlike(): void
    {
        $lines = UpgradeReport::lines(self::move([
            'refused' => [
                self::refusal('drupal/entity_reference_revisions', 1, 'clean'),
                self::refusal('drupal/pathauto', 12, 'clean'),
            ],
            'depths' => [['package' => 'drupal/core', 'number' => 3, 'depth' => 1]],
        ]));

        self::assertContains('    drupal/entity_reference_revisions   #1  re-rolls clean', $lines);
        self::assertContains('    drupal/pathauto                    #12  re-rolls clean', $lines);
        self::assertContains('    drupal/core                         #3  -p1', $lines);
    }
}
