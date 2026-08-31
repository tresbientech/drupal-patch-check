<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Coverage;

final class CoverageTest extends TestCase
{
    private const FORK = 'not a drupal.org release';

    private const HOST = 'the service does not fetch from that host';

    public function testARunSaysHowManyPatchesItChecked(): void
    {
        $coverage = $this->coverage(53);

        self::assertSame(['drupatch: checked 53 patches'], $coverage->lines());
        self::assertFalse($coverage->isVacuous());
    }

    // One package, one line. Six lines naming six patches on a package
    // the run never touched is detail nobody acts on.
    public function testAPackageIsNamedOnceWithItsPatchCount(): void
    {
        $coverage = $this->coverage(53, [
            $this->skip('acquia/cohesion', 'PHP warning fix'),
            $this->skip('acquia/cohesion', 'Fix compatibility with ECA'),
            $this->skip('acquia/cohesion', 'Tmgmt issue fix'),
        ]);

        self::assertSame([
            'drupatch: checked 53 patches; skipped 3 on 1 package',
            '  skipped  acquia/cohesion, 3 patches (not a drupal.org release)',
        ], $coverage->lines());
    }

    public function testTwoPackagesGetALineEach(): void
    {
        $coverage = $this->coverage(50, [
            $this->skip('acquia/cohesion', 'One'),
            $this->skip('drupal/nouislider_js', 'Two'),
            $this->skip('acquia/cohesion', 'Three'),
        ]);

        self::assertSame([
            'drupatch: checked 50 patches; skipped 3 on 2 packages',
            '  skipped  acquia/cohesion, 2 patches (not a drupal.org release)',
            '  skipped  drupal/nouislider_js, 1 patch (not a drupal.org release)',
        ], $coverage->lines());
    }

    // The reason is the patch's, not the package's, so a package can
    // appear twice when its patches were skipped for different reasons.
    public function testOnePackageWithTwoReasonsGetsALinePerReason(): void
    {
        $coverage = $this->coverage(50, [
            $this->skip('drupal/webform', 'From our gitlab', self::HOST),
            $this->skip('drupal/webform', 'From our other gitlab', self::HOST),
        ]);

        self::assertSame([
            'drupatch: checked 50 patches; skipped 2 on 1 package',
            '  skipped  drupal/webform, 2 patches (the service does not fetch from that host)',
        ], $coverage->lines());
    }

    public function testASinglePatchReadsAsEnglish(): void
    {
        $coverage = $this->coverage(1, [$this->skip('acme/private', 'In-house fix')]);

        self::assertSame([
            'drupatch: checked 1 patch; skipped 1 on 1 package',
            '  skipped  acme/private, 1 patch (not a drupal.org release)',
        ], $coverage->lines());
    }

    public function testNoPatchTitleReachesTheOutput(): void
    {
        $lines = \implode("\n", $this->coverage(53, [$this->skip('acquia/cohesion', 'Page builder lock logic')])->lines());

        self::assertStringNotContainsString('Page builder lock logic', $lines);
    }

    public function testASiteWhoseEveryPatchWasSkippedIsToldPlainly(): void
    {
        $coverage = $this->coverage(0, [$this->skip('drupal/webform', 'Style fix')]);

        self::assertSame(
            ['drupatch: no patch could be checked. Every declared patch is on a package the service has no release for.'],
            $coverage->lines(),
        );
    }

    public function testASiteDeclaringNoPatchesSaysSo(): void
    {
        self::assertSame(['drupatch: checked 0 patches'], $this->coverage(0)->lines());
    }

    public function testDeclaringPatchesAndCheckingNoneIsVacuous(): void
    {
        self::assertTrue($this->coverage(0, [$this->skip('drupal/a', 'Fix')])->isVacuous());
    }

    public function testDeclaringNoPatchesAtAllIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(0)->isVacuous());
    }

    public function testCheckingSomePatchesIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(1, [$this->skip('drupal/a', 'Fix')])->isVacuous());
    }

    /**
     * @return array{package: string, title: string, reason: string}
     */
    private function skip(string $package, string $title, string $reason = self::FORK): array
    {
        return ['package' => $package, 'title' => $title, 'reason' => $reason];
    }

    /**
     * @param list<array{package: string, title: string, reason: string}> $skipped
     */
    private function coverage(int $patches, array $skipped = []): Coverage
    {
        return new Coverage($patches, $skipped);
    }
}
