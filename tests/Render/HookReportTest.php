<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Render\HookReport;
use Tresbien\Drupatch\Tests\PlanFactory;

final class HookReportTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'counts' => ['needs-reroll' => 1, 'shipped' => 1, 'still-needed' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['title' => 'Fix a', 'verdict' => 'needs-reroll']),
                $this->row(['title' => 'Fix b', 'verdict' => 'still-needed']),
                $this->row(['package' => 'drupal/core', 'version' => '11.4.5', 'title' => 'Menu cache', 'verdict' => 'shipped']),
            ],
        ]);
    }

    public function testTalliesEveryVerdictOnTheFirstLine(): void
    {
        $first = HookReport::lines($this->plan())[0];

        self::assertStringContainsString('3 patches', $first);
        self::assertStringContainsString('11.4.5', $first);
        self::assertStringContainsString('1 needs-reroll', $first);
        self::assertStringContainsString('1 still-needed', $first);
    }

    public function testListsOnlyThePatchesNeedingAttention(): void
    {
        $lines = \implode("\n", HookReport::lines($this->plan()));

        self::assertStringContainsString('Fix a', $lines);
        self::assertStringContainsString('Menu cache', $lines);
        self::assertStringNotContainsString('Fix b', $lines, 'a still-needed patch belongs in the tally, not in the list');
    }

    public function testPointsAtTheCommandThatShowsMore(): void
    {
        self::assertStringContainsString('composer drupal-patch-check', \implode("\n", HookReport::lines($this->plan())));
    }

    public function testPrintsAWarningTheTallyDependsOn(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);

        $lines = HookReport::lines($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[1], 'the warning goes under the tally, marked and whole');
    }

    public function testNamesThePackagesThatBlockAnUpgrade(): void
    {
        self::assertStringContainsString('drupal/domain', \implode("\n", HookReport::lines($this->plan())));
    }

    public function testSaysNothingWhenTheSiteHasNoPatches(): void
    {
        self::assertSame([], HookReport::lines($this->planFrom()));
    }

    public function testAVerdictTheServerAddedLaterIsStillCountedAndListed(): void
    {
        $plan = $this->planFrom([
            'counts' => ['quarantined' => 1],
            'patches' => [$this->row(['title' => 'Odd one', 'verdict' => 'quarantined'])],
        ]);

        $lines = \implode("\n", HookReport::lines($plan));

        self::assertStringContainsString('1 quarantined', $lines, 'an unknown verdict must still reach the tally');
        self::assertStringContainsString('Odd one', $lines, 'an unknown verdict is not silently treated as clean');
    }

    public function testCutsALongListRatherThanFloodingTheUpdate(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; ++$i) {
            $rows[] = $this->row(['title' => "p$i", 'verdict' => 'needs-reroll']);
        }
        $lines = HookReport::lines($this->planFrom(['counts' => ['needs-reroll' => 40], 'patches' => $rows]));

        self::assertLessThan(25, \count($lines));
        self::assertContains('  …', $lines, 'a long list is cut, not printed whole');
    }
}
