<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\AddReport;

#[CoversClass(AddReport::class)]
class AddReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return array{issue: string, title: string, package: string, path: string, verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int, candidates: list<array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}>, chosen: int, error: string, dryRun: bool}
     */
    private static function outcome(array $over = []): array
    {
        /** @var array{issue: string, title: string, package: string, path: string, verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int, candidates: list<array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}>, chosen: int, error: string, dryRun: bool} $out */
        $out = $over + [
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

        return $out;
    }

    public function testItNamesTheIssueTheCopyTheVerdictAndTheDeclaration(): void
    {
        self::assertSame([
            '',
            '  drupal/webform, issue 3521733',
            '',
            '  vendored patch/webform/mr940.diff',
            '  verdict  applies',
            '',
            '  declared 3521733: Fix the back/forward cache',
        ], AddReport::lines(self::outcome()));
    }

    // The manager's own commands write straight to the terminal, so the
    // verdict block is printed before they run.
    public function testTheVerdictComesBeforeTheDeclaration(): void
    {
        $lines = AddReport::lines(self::outcome());
        $verdict = \array_search('  verdict  applies', $lines, true);
        $declared = \array_search('  declared 3521733: Fix the back/forward cache', $lines, true);

        self::assertIsInt($verdict);
        self::assertIsInt($declared);
        self::assertLessThan($declared, $verdict);
    }

    public function testAVerdictWithAReasonCarriesIt(): void
    {
        $lines = AddReport::lines(self::outcome(['verdict' => 'unknown', 'reason' => 'no release for 6.2.9']));

        self::assertContains('  verdict  unknown, no release for 6.2.9', $lines);
    }

    public function testADryRunStopsAfterTheVerdict(): void
    {
        $lines = AddReport::lines(self::outcome(['dryRun' => true]));

        self::assertSame('  nothing written (--dry-run)', \end($lines));
        self::assertNotContains('  declared 3521733: Fix the back/forward cache', $lines);
    }

    public function testARefusalPrintsTheReasonAndNothingElse(): void
    {
        self::assertSame(
            ['', '  <error>this site does not install drupal/token, so there is nothing to patch</error>'],
            AddReport::lines(self::outcome(['error' => 'this site does not install drupal/token, so there is nothing to patch']))
        );
    }

    /**
     * @return list<array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}>
     */
    private static function ordered(): array
    {
        return [
            ['iid' => '940', 'title' => 'Fix the cache', 'target' => '6.2.x', 'draft' => false, 'state' => 'opened', 'updated' => '2026-09-09T14:27:14Z'],
            ['iid' => '912', 'title' => 'The same fix on the dev branch', 'target' => '6.x', 'draft' => true, 'state' => 'opened', 'updated' => '2026-08-02T09:00:00Z'],
        ];
    }

    public function testEachCandidateNamesItsBranchItsPushAndItsTitle(): void
    {
        self::assertSame([
            '',
            '  2 merge requests on issue 3521733',
            '',
            '  [0] !940    6.2.x     2026-09-09  Fix the cache',
            '  [1] !912    6.x       2026-08-02  The same fix on the dev branch  (draft)',
            '',
        ], AddReport::candidates(self::ordered(), '3521733'));
    }

    public function testASingleCandidateIsCountedInTheSingular(): void
    {
        $lines = AddReport::candidates([self::ordered()[0]], '3521733');

        self::assertContains('  1 merge request on issue 3521733', $lines);
    }

    // A run that chose from a list says which one it took.
    public function testARunThatChoseSaysWhichItTook(): void
    {
        $lines = AddReport::lines(self::outcome(['candidates' => self::ordered(), 'chosen' => 1]));

        self::assertContains('  took !912    6.x       2026-08-02  The same fix on the dev branch  (draft)', $lines);
    }

    public function testARunWithNoListSaysNothingAboutOne(): void
    {
        foreach (AddReport::lines(self::outcome()) as $line) {
            self::assertStringNotContainsString('took !', $line);
        }
    }

    // The release carries the change, so the copy is a file nobody applies.
    public function testAMergedVerdictSaysNothingWasDeclared(): void
    {
        $lines = AddReport::lines(self::outcome(['verdict' => 'merged', 'wrote' => '', 'declared' => false, 'exit' => 0]));

        self::assertContains('  the release already carries this change, so nothing was declared', $lines);
        self::assertNotContains('  declared 3521733: Fix the back/forward cache', $lines);
    }

    public function testARerollWrittenOverTheCopyIsNotNamedTwice(): void
    {
        $lines = AddReport::lines(self::outcome(['verdict' => 'conflicts']));

        foreach ($lines as $line) {
            self::assertStringNotContainsString('re-roll', $line);
        }
    }

    // A conflict file sits beside the copy, so the run names it.
    public function testAConflictFileIsNamedWithItsOpenRegions(): void
    {
        $lines = AddReport::lines(self::outcome([
            'verdict' => 'conflicts',
            'wrote' => 'patch/webform/mr940.conflict.patch',
            'regions' => ['src/Form.php region 0', 'src/Form.php region 1'],
            'declared' => false,
            'exit' => 1,
        ]));

        self::assertContains('  re-roll  patch/webform/mr940.conflict.patch', $lines);
        self::assertContains('           src/Form.php region 0', $lines);
        self::assertContains('  decide 2 regions in patch/webform/mr940.conflict.patch, then run `composer drupatch:reroll`', $lines);
    }

    public function testARunThatCouldNotWriteSaysWhy(): void
    {
        $lines = AddReport::lines(self::outcome([
            'verdict' => 'conflicts',
            'reason' => 'it has uncommitted changes',
            'wrote' => '',
            'declared' => false,
            'exit' => 1,
        ]));

        self::assertContains('  nothing was declared: it has uncommitted changes', $lines);
    }
}
