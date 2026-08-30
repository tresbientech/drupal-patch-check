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
        $webform = \array_search('  drupal/webform 6.2.9 → 6.3.2', $lines, true);

        self::assertIsInt($webform);
        self::assertStringContainsString('Fix a', $lines[$webform + 1]);
        self::assertStringContainsString('Fix b', $lines[$webform + 2]);
    }

    public function testSaysWhyABlockedPatchHasNoVerdict(): void
    {
        $out = \implode("\n", Table::lines($this->plan()));

        self::assertStringContainsString('no release for the target', $out);
        self::assertStringContainsString('drupal/domain has no release for 11.4.5', $out);
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

    public function testEndsWithBothTallies(): void
    {
        $out = \implode("\n", Table::lines($this->plan()));

        self::assertStringContainsString('packages: 30 current, 1 no_release', $out);
        self::assertStringContainsString('patches:  1 needs-reroll, 1 still-needed, 1 unknown', $out);
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
