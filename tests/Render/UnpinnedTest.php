<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Run;
use TresBienTech\Drupatch\Render\Coverage;
use TresBienTech\Drupatch\Render\HookReport;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;

/**
 * What a run says about a patch declared as a merge request URL.
 */
class UnpinnedTest extends TestCase
{
    use PlanFactory;

    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch';

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function table(array $rows): string
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => $rows]));

        return \implode("\n", Report::lines($plan, new Coverage(\count($rows), [], [], []), 100));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function mrRow(array $fields = []): array
    {
        return $this->row($fields + ['source' => self::MR]);
    }

    public function testARowDeclaredAsAMergeRequestCarriesARedNote(): void
    {
        $table = self::table([$this->mrRow()]);

        self::assertStringContainsString('<fg=red>declared as a merge request URL</>', $table);
    }

    public function testALocalRowCarriesNoNote(): void
    {
        $table = self::table([$this->row()]);

        self::assertStringNotContainsString('merge request URL', $table);
    }

    public function testTheTableSaysWhoCanChangeTheseBytes(): void
    {
        $table = self::table([$this->mrRow(['title' => 'a']), $this->mrRow(['title' => 'b']), $this->mrRow(['title' => 'c'])]);

        self::assertStringContainsString('3 patches are declared as merge request URLs. Anyone with a drupal.org', $table);
        self::assertStringContainsString('account can push to a merge request, so what composer applies here can', $table);
        self::assertStringContainsString('change between two installs. Run: composer drupatch:pin', $table);
    }

    public function testOnePatchReadsAsOne(): void
    {
        $table = self::table([$this->mrRow()]);

        self::assertStringContainsString('1 patch is declared as a merge request URL. Anyone with a drupal.org', $table);
    }

    // A URL on any other host is somebody else's rule, and a `.diff` is the
    // same merge request as a `.patch`.
    public function testOnlyDrupalcodeMergeRequestsCount(): void
    {
        $table = self::table([
            $this->row(['title' => 'a', 'source' => 'https://github.com/drupal/webform/pull/12.patch']),
            $this->row(['title' => 'b', 'source' => 'https://www.drupal.org/files/issues/2026-01-01/webform-3521733-12.patch']),
            $this->row(['title' => 'c', 'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940.diff']),
        ]);

        self::assertStringContainsString('1 patch is declared as a merge request URL', $table);
    }

    // A re-roll run writes files from declarations that keep changing
    // under the site, so it says the same thing a plain run says.
    public function testAWriteRunSaysItToo(): void
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => [$this->mrRow()]]));
        $outcomes = Outcomes::fromWrite(['written' => [], 'refused' => []]);

        $out = \implode("\n", Report::lines($plan, new Coverage(1, [], [], []), 100, $outcomes));

        self::assertStringContainsString('1 patch is declared as a merge request URL. Anyone with a drupal.org', $out);
        self::assertStringContainsString('change between two installs. Run: composer drupatch:pin', $out);
    }

    public function testTheSummaryListsThem(): void
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => [
            $this->mrRow(['title' => 'a']),
            $this->row(['title' => 'b']),
        ]]));

        $summary = Report::summary($plan);

        self::assertSame([['package' => 'drupal/webform', 'title' => 'a', 'source' => self::MR]], $summary['unpinned']);
    }

    public function testTheSummaryLeavesTheKeyOutWhenThereAreNone(): void
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => [$this->row()]]));

        self::assertArrayNotHasKey('unpinned', Report::summary($plan));
    }

    // The run itself says it now, before the report the hook setting gates,
    // so the report saying it too would say it twice.
    public function testTheHookLeavesTheWarningToTheRun(): void
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => [
            $this->mrRow(['title' => 'a', 'verdict' => 'conflicts']),
            $this->mrRow(['title' => 'b', 'verdict' => 'conflicts']),
        ]]));

        $lines = \implode("\n", HookReport::lines($plan));

        self::assertStringContainsString('2 conflicts after this update', $lines);
        self::assertStringNotContainsString('merge request', $lines);
    }

    public function testTheHookSaysNothingWithoutOne(): void
    {
        $plan = Plan::fromArray(self::wire(['target_core' => '11.4.5', 'patches' => [$this->row()]]));

        self::assertSame([], HookReport::lines($plan));
    }
}
