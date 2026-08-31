<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Render\Table;
use Tresbien\Drupatch\Tests\PlanFactory;

final class TableTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'counts' => ['needs-reroll' => 1, 'still-needed' => 1, 'unknown' => 1],
            'package_counts' => ['current' => 30, 'no_release' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['installed' => '6.2.9', 'version' => '6.3.2', 'title' => 'Fix a', 'verdict' => 'needs-reroll']),
                $this->row(['installed' => '6.2.9', 'version' => '6.3.2', 'title' => 'Fix b', 'verdict' => 'still-needed']),
                $this->row([
                    'package' => 'drupal/domain', 'project' => 'domain', 'installed' => '2.0.1', 'version' => '',
                    'title' => 'Fix c', 'verdict' => 'unknown', 'note' => 'drupal/domain has no release for 11.4.5',
                ]),
            ],
        ]);
    }

    public function testNamesTheReleaseEachVerdictIsAbout(): void
    {
        self::assertStringContainsString('drupal/webform 6.2.9 → 6.3.2', \implode("\n", Table::lines($this->plan())));
    }

    public function testGroupsPatchesUnderTheirPackage(): void
    {
        $lines = Table::lines($this->plan());
        $webform = \array_search('  drupal/webform 6.2.9 → 6.3.2   1 needs-reroll, 1 still-needed', $lines, true);

        self::assertIsInt($webform);
        self::assertStringContainsString('Fix a', $lines[$webform + 1]);
        self::assertStringContainsString('Fix b', $lines[$webform + 2]);
    }

    public function testSaysWhyABlockedPatchHasNoVerdict(): void
    {
        $out = \implode("\n", Table::lines($this->plan()));

        self::assertStringContainsString('drupal/domain has no release for 11.4.5', $out);
    }

    public function testNamesTheFileARerollWillHaveToFix(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-reroll',
            'result' => ['hunks_failed' => [
                ['file' => 'tests/src/Functional/SimplesitemapTest.php', 'reason' => 'does not exist in index'],
                ['file' => 'src/Manager.php', 'reason' => 'patch does not apply'],
            ]],
        ])]]);

        $out = \implode("\n", Table::lines($plan));

        self::assertStringContainsString('tests/src/Functional/SimplesitemapTest.php: does not exist in index', $out);
        // One line is the size of the hint; the rest is in the patch.
        self::assertStringNotContainsString('src/Manager.php', $out);
    }

    public function testSaysNothingAboutFailedHunksOnAVerdictThatStands(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'verdict' => 'still-needed',
            'result' => ['hunks_failed' => [['file' => 'src/Manager.php', 'reason' => 'patch does not apply']]],
        ])]]);

        self::assertStringNotContainsString('src/Manager.php', \implode("\n", Table::lines($plan)));
    }

    public function testRendersARerollWithoutFailedHunksAsBefore(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-reroll' => 1], 'patches' => [
            $this->row(['verdict' => 'needs-reroll', 'title' => 'Fix a']),
        ]]);
        $lines = Table::lines($plan);
        $at = \array_search('  drupal/webform 6.2.9   1 needs-reroll', $lines, true);

        self::assertIsInt($at);
        self::assertStringContainsString('needs-reroll', $lines[$at + 1]);
        self::assertStringContainsString('Fix a', $lines[$at + 1]);
        self::assertSame('', $lines[$at + 2] ?? '');
    }

    public function testShowsWhyAPatchCouldNotBeJudged(): void
    {
        $plan = $this->planFrom(['counts' => ['unknown' => 1], 'patches' => [$this->row([
            'verdict' => 'unknown',
            'result' => ['error' => 'local patch file not supplied: send its text under patch_files'],
        ])]]);

        self::assertStringContainsString('local patch file not supplied', \implode("\n", Table::lines($plan)));
    }

    public function testNamesTheLocalPatchesWhoseTextWasNotSent(): void
    {
        $plan = $this->planFrom(['missing_files' => ['patchs/webform.patch']]);

        self::assertStringContainsString('patchs/webform.patch', \implode("\n", Table::lines($plan)));
    }

    public function testLeadsWithAWarningTheCountsDependOn(): void
    {
        $plan = $this->planFrom([
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);

        $lines = Table::lines($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[2], 'the warning belongs above the rows, marked and whole');
        self::assertSame('', $lines[3], 'a blank line separates the warnings from the packages');
    }

    // The verdict is still-needed, so the row is not work; the line says
    // the patch needed a looser reading than git apply gives.
    public function testSaysWhenAStrictApplyRefusedAPatchThatStillApplies(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'still-needed',
            'result' => ['strict_refused' => 'the patch carries the packaging block as context'],
        ])]]);

        $lines = Table::lines($plan);
        $row = self::rowWith($lines, '· still-needed  Fix the alter hook');

        self::assertSame(
            '                    the patch carries the packaging block as context',
            $lines[$row + 1],
            'the note belongs under its row, indented and whole'
        );
    }

    // A row that only broke because of an earlier patch must say so, or
    // the wrong patch gets re-rolled.
    public function testNamesTheEarlierPatchARowWasJudgedWithout(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'needs-reroll',
            'result' => ['judged_without' => 'Domain content translations permissions_files'],
        ])]]);

        $lines = Table::lines($plan);
        $row = self::rowWith($lines, '<error>!</error> needs-reroll  Fix the alter hook');

        self::assertSame(
            '                    judged without "Domain content translations permissions_files", which did not apply',
            $lines[$row + 1]
        );
    }

    public function testThePackageNeedingAPersonComesFirst(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/zzz', 'title' => 'Fix z', 'verdict' => 'shipped']),
            $this->row(['package' => 'drupal/aaa', 'title' => 'Fix a', 'verdict' => 'needs-reroll']),
        ]]);

        self::assertSame(
            ['drupal/aaa', 'drupal/zzz'],
            self::packagesInOrder(Table::lines($plan)),
        );
    }

    public function testPackagesWithTheSameWorstVerdictSortByName(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/token', 'title' => 'Fix t', 'verdict' => 'needs-reroll']),
            $this->row(['package' => 'drupal/admin', 'title' => 'Fix b', 'verdict' => 'needs-reroll']),
            $this->row(['package' => 'drupal/media', 'title' => 'Fix m', 'verdict' => 'needs-reroll']),
        ]]);

        self::assertSame(
            ['drupal/admin', 'drupal/media', 'drupal/token'],
            self::packagesInOrder(Table::lines($plan)),
        );
    }

    public function testRowsInsideAPackageSortByVerdictThenTitle(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Zebra', 'verdict' => 'shipped']),
            $this->row(['title' => 'Alpha', 'verdict' => 'shipped']),
            $this->row(['title' => 'Yak', 'verdict' => 'needs-reroll']),
        ]]);

        self::assertSame(['Yak', 'Alpha', 'Zebra'], self::titlesInOrder(Table::lines($plan)));
    }

    public function testABlankLineSeparatesEachPackage(): void
    {
        $lines = Table::lines($this->plan());
        $domain = \array_search('  drupal/domain 2.0.1   1 unknown', $lines, true);

        self::assertIsInt($domain);
        self::assertSame('', $lines[$domain - 1], 'a package is preceded by a blank line');
    }

    public function testOnePackageRendersWithoutADoubledBlankLine(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row()]]);
        $lines = Table::lines($plan);

        foreach ($lines as $i => $line) {
            if ('' === $line && '' === ($lines[$i + 1] ?? 'x')) {
                self::fail('two blank lines in a row at line '.$i);
            }
        }
        self::assertNotSame([], $lines);
    }

    public function testTheTallyOmitsVerdictsThePackageHasNone(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'shipped'])]]);

        self::assertStringContainsString('drupal/webform 6.2.9   1 shipped', \implode("\n", Table::lines($plan)));
    }

    public function testTheTallyIsWorstVerdictFirst(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'A', 'verdict' => 'shipped']),
            $this->row(['title' => 'B', 'verdict' => 'unknown']),
            $this->row(['title' => 'C', 'verdict' => 'needs-reroll']),
        ]]);

        self::assertStringContainsString(
            '1 needs-reroll, 1 unknown, 1 shipped',
            \implode("\n", Table::lines($plan)),
        );
    }

    public function testRenderingOnePlanTwiceIsIdentical(): void
    {
        $plan = $this->plan();

        self::assertSame(Table::lines($plan), Table::lines($plan));
    }

    /**
     * The index of the row whose text starts with `$opening`.
     *
     * @param list<string> $lines
     */
    private static function rowWith(array $lines, string $opening): int
    {
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, '    '.$opening)) {
                return $i;
            }
        }
        self::fail('no row opens with '.$opening);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function packagesInOrder(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (1 === \preg_match('/^  (drupal\/\S+) /', $line, $m)) {
                $out[] = $m[1];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function titlesInOrder(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (1 === \preg_match('/^    \S+ +\S+ +(.+?)(?:  +\S+)?$/u', $line, $m)) {
                $out[] = \trim($m[1]);
            }
        }

        return $out;
    }

    public function testEveryRowEndsWithThePatchFilename(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'patchs/webform-3401234.patch']),
        ]]);

        self::assertStringEndsWith('webform-3401234.patch', Table::lines($plan)[3]);
    }

    public function testAUrlPatchShowsItsLastSegment(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'https://www.drupal.org/files/issues/2025-01-02/webform-3399999-12.patch']),
        ]]);

        self::assertStringEndsWith('webform-3399999-12.patch', Table::lines($plan)[3]);
    }

    public function testAQueryStringIsNotPartOfTheFilename(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'https://example.test/p/fix.patch?token=abc']),
        ]]);

        self::assertStringEndsWith('fix.patch', Table::lines($plan)[3]);
    }

    // The label already is the source, so a column repeating it would
    // print the same string twice on one row.
    public function testAPatchWithNoTitleGetsNoFilenameColumn(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => '', 'source' => 'patchs/webform.patch']),
        ]]);
        $row = Table::lines($plan)[3];

        self::assertSame(1, \substr_count($row, 'patchs/webform.patch'));
    }

    public function testANarrowTerminalStillPrintsOneRowPerPatch(): void
    {
        $lines = Table::lines($this->plan(), 80);
        $rows = \array_filter($lines, static fn (string $l): bool => 1 === \preg_match('/^    \S/u', $l));

        self::assertCount(3, $rows);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(80, \mb_strlen(\strip_tags($line)), 'a row overran the terminal: '.$line);
        }
    }

    public function testAWideTerminalKeepsLongTitlesWhole(): void
    {
        $title = 'Allow numeric machine names in webform handler configuration';
        $plan = $this->planFrom(['patches' => [$this->row(['title' => $title])]]);

        self::assertStringContainsString($title, \implode("\n", Table::lines($plan, 120)));
    }

    public function testANarrowTerminalShortensTheTitle(): void
    {
        $title = 'Allow numeric machine names in webform handler configuration forms';
        $plan = $this->planFrom(['patches' => [$this->row(['title' => $title])]]);

        $out = \implode("\n", Table::lines($plan, 80));
        self::assertStringNotContainsString($title, $out);
        self::assertStringContainsString('…', $out);
    }

    public function testATitleIsNeverWrapped(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'title' => \str_repeat('long title ', 40),
        ])]]);
        $lines = Table::lines($plan, 80);

        self::assertCount(1, \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'long title')));
    }

    public function testTheFilenamesLineUpInAColumn(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Short', 'source' => 'patchs/a.patch']),
            $this->row(['title' => 'A considerably longer patch title', 'source' => 'patchs/b.patch']),
        ]]);
        $rows = \array_values(\array_filter(
            Table::lines($plan, 100),
            static fn (string $l): bool => 1 === \preg_match('/^    \S/u', $l),
        ));

        self::assertCount(2, $rows);
        self::assertSame(
            \mb_strpos($rows[0], 'a.patch'),
            \mb_strpos($rows[1], 'b.patch'),
            'the filename column starts at the same offset on every row',
        );
    }

    public function testARowNeverEndsInWhitespace(): void
    {
        foreach (Table::lines($this->plan(), 100) as $line) {
            self::assertSame(\rtrim($line), $line, 'a line ends in padding: '.$line);
        }
    }

    public function testContinuationLinesSitUnderTheTitle(): void
    {
        $lines = Table::lines($this->plan(), 100);
        $row = self::rowWith($lines, '<comment>?</comment> unknown');
        $detail = $lines[$row + 1];

        self::assertSame(
            \mb_strpos(\strip_tags($lines[$row]), 'Fix c'),
            \mb_strlen($detail) - \mb_strlen(\ltrim($detail)),
            'the detail line starts where the title does',
        );
    }

    public function testTheFooterIsTheLastThingTheReportPrints(): void
    {
        $written = [$this->writtenFile('patchs/webform.patch')];
        $lines = Table::report($this->plan(), $written, 100);
        $last = $lines[\count($lines) - 1];

        self::assertStringContainsString('--reroll', $last);
    }

    public function testTheWrittenFilesPrintAboveTheFooter(): void
    {
        $written = [$this->writtenFile('patchs/webform.patch')];
        $lines = Table::report($this->plan(), $written, 100);

        $wrote = self::indexOfLineContaining($lines, 'wrote patchs/webform.patch');
        $footer = self::indexOfLineContaining($lines, '--reroll');

        self::assertLessThan($footer, $wrote);
    }

    public function testAReportWithNothingToRunHasNoFooter(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row()]]);
        $lines = Table::report($plan, [], 100);

        self::assertSame([], Table::footer($plan));
        self::assertStringNotContainsString('Next:', \implode("\n", $lines));
    }

    public function testTheReportIsTheRowsThenTheFilesThenTheFooter(): void
    {
        $written = [$this->writtenFile('patchs/webform.patch')];
        $plan = $this->plan();

        self::assertSame(
            \array_merge(Table::lines($plan, 100), Table::written($written), Table::footer($plan)),
            Table::report($plan, $written, 100),
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function indexOfLineContaining(array $lines, string $needle): int
    {
        foreach ($lines as $i => $line) {
            if (\str_contains($line, $needle)) {
                return $i;
            }
        }
        self::fail('no line contains '.$needle);
    }

    public function testEveryRowOpensWithItsMark(): void
    {
        $lines = Table::lines($this->plan());
        $rows = \array_values(\array_filter(
            $lines,
            static fn (string $line): bool => 1 === \preg_match('/^    \S/', $line),
        ));

        self::assertCount(3, $rows);
        foreach ($rows as $line) {
            self::assertMatchesRegularExpression(
                '/^    (<\w+>)?\S(<\/\w+>)? (needs-reroll|still-needed|shipped|unknown) /u',
                $line,
                'a row must open with its mark and still name its verdict',
            );
        }
    }

    public function testAVerdictTheServerAddedLaterStillRenders(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-a-human' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-a-human',
        ])]]);

        self::assertStringContainsString(
            'needs-a-human',
            \implode("\n", Table::lines($plan)),
        );
    }

    public function testABlockedPackageHeadingNamesAReleaseAndCarriesNoArrow(): void
    {
        $lines = Table::lines($this->plan());

        self::assertContains('  drupal/domain 2.0.1   1 unknown', $lines);
        self::assertStringNotContainsString('→ no release for the target', \implode("\n", $lines));
    }

    public function testTwoSpellingsOfOneBranchAreNotAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => 'dev-2.x', 'version' => '2.x-dev',
        ])]]);

        self::assertContains('  drupal/select2 dev-2.x   1 still-needed', Table::lines($plan));
    }

    public function testEitherSpellingOfOneBranchReadsAsOne(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => '2.x-dev', 'version' => 'dev-2.x',
        ])]]);

        self::assertContains('  drupal/select2 2.x-dev   1 still-needed', Table::lines($plan));
    }

    public function testTwoDifferentBranchesAreStillAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => 'dev-1.x', 'version' => '2.x-dev',
        ])]]);

        self::assertContains('  drupal/select2 dev-1.x → 2.x-dev   1 still-needed', Table::lines($plan));
    }

    public function testAReleaseUpgradeIsStillAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['still-needed' => 1], 'patches' => [$this->row([
            'installed' => '6.2.9', 'version' => '6.3.2',
        ])]]);

        self::assertContains('  drupal/webform 6.2.9 → 6.3.2   1 still-needed', Table::lines($plan));
    }

    public function testAWarningSitsAboveThePackageItNames(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ]);

        $lines = Table::lines($plan);
        $heading = \array_search('  drupal/webform 6.2.9   1 still-needed', $lines, true);

        self::assertIsInt($heading);
        self::assertSame(
            '  <comment>! drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.</comment>',
            $lines[$heading - 1],
        );
    }

    public function testAWarningSitsBelowThePrecedingPackagesLastRow(): void
    {
        $plan = $this->planFrom([
            'counts' => ['needs-reroll' => 1, 'still-needed' => 1],
            'warnings' => ['drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.'],
            'patches' => [
                $this->row(['title' => 'Alpha', 'verdict' => 'needs-reroll']),
                $this->row(['package' => 'drupal/domain', 'project' => 'domain', 'version' => '2.0.1', 'title' => 'Beta']),
            ],
        ]);

        $lines = Table::lines($plan);
        $warning = \array_search('  <comment>! drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.</comment>', $lines, true);

        self::assertIsInt($warning);
        self::assertStringContainsString('Alpha', $lines[$warning - 2], 'the previous package keeps its last row');
        self::assertSame('', $lines[$warning - 1], 'a blank line separates the packages');
        self::assertStringContainsString('drupal/domain 2.0.1', $lines[$warning + 1]);
    }

    public function testAWarningMatchingNoPrintedPackageStaysAboveTheReport(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'warnings' => ['drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.'],
            'patches' => [$this->row()],
        ]);

        $lines = Table::lines($plan);

        self::assertSame('  <comment>! drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.</comment>', $lines[2]);
        self::assertSame('', $lines[3]);
    }

    public function testAWarningNamingAPackageIsNeverPrintedTwice(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ]);

        $marked = \array_filter(Table::lines($plan), static fn (string $line): bool => \str_contains($line, '! drupal/webform 6.3.2'));

        self::assertCount(1, $marked);
    }

    public function testEndsOnThePatchTally(): void
    {
        $out = \implode("\n", Table::lines($this->plan()));

        self::assertStringContainsString('  patches: 1 needs-reroll, 1 still-needed, 1 unknown', $out);
    }

    public function testTheFooterCarriesNoPackageTally(): void
    {
        self::assertStringNotContainsString('packages:', \implode("\n", Table::lines($this->plan())));
    }

    public function testTheFooterDoesNotListTheBlockedPackages(): void
    {
        self::assertNotContains('  no release for 11.4.5: drupal/domain', Table::lines($this->plan()));
    }

    public function testTheFooterStillNamesPatchTextThatWasNotSent(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'patches' => [$this->row()],
            'missing_files' => ['drupal/webform "Fix a"'],
        ]);

        self::assertStringContainsString('patch text not sent for: drupal/webform "Fix a"', \implode("\n", Table::lines($plan)));
    }

    public function testSaysWhenThePlanRanAgainstTheInstalledCore(): void
    {
        $plan = $this->planFrom(['target_is_installed' => true, 'patches' => [$this->row()]]);

        self::assertStringContainsString('the core this site runs', Table::lines($plan)[0]);
    }

    public function testMarksACleanRerollAsVerifiedAndAConflictAsUnusable(): void
    {
        $lines = \implode("\n", Table::written([
            $this->writtenFile('patches/webform-fix-a-1234abcd.patch'),
            $this->writtenFile('patches/token-fix-d-5678efgh.conflict.patch', 'conflicts', 'drupal/token', 'Fix d', false),
        ]));

        self::assertStringContainsString('verified against the release by the server', $lines);
        self::assertStringContainsString('not usable as patches', $lines);
    }

    public function testSaysNothingWhenNoFileWasWritten(): void
    {
        self::assertSame([], Table::written([]));
    }
}
