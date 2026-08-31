<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Coverage;

final class CoverageTest extends TestCase
{
    public function testARunSaysHowManyPatchesItChecked(): void
    {
        $coverage = $this->coverage(53);

        self::assertSame(['drupatch: checked 53 patches'], $coverage->lines());
        self::assertFalse($coverage->isVacuous());
    }

    public function testHeldBackPatchesAreNamedOnTheTerminal(): void
    {
        $coverage = $this->coverage(53, ['drupal/acme "Fix"', 'acquia/cohesion "PHP warning fix"']);

        $lines = $coverage->lines();

        self::assertSame('drupatch: checked 53 patches; held back 2', $lines[0]);
        self::assertSame('  held back  drupal/acme "Fix"', $lines[1]);
        self::assertSame('  held back  acquia/cohesion "PHP warning fix"', $lines[2]);
    }

    public function testAPackageCarryingNoPatchIsNeverNamed(): void
    {
        $coverage = $this->coverage(53);

        self::assertSame(['drupatch: checked 53 patches'], $coverage->lines());
    }

    public function testAForkedPackageIsReachedThroughItsHeldBackPatch(): void
    {
        $coverage = $this->coverage(52, ['drupal/nouislider_js "Custom fix"']);

        self::assertSame('  held back  drupal/nouislider_js "Custom fix"', $coverage->lines()[1]);
    }

    public function testTheHeldBackTotalCountsPatches(): void
    {
        $coverage = $this->coverage(4, ['drupal/a "One"', 'drupal/b "Two"', 'drupal/c "Three"']);

        self::assertSame('drupatch: checked 4 patches; held back 3', $coverage->lines()[0]);
    }

    public function testASiteWhoseEveryPatchWasHeldBackIsToldPlainly(): void
    {
        $coverage = $this->coverage(0, ['drupal/webform "Style fix"']);

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
        self::assertTrue($this->coverage(0, ['drupal/acme "Fix"'])->isVacuous());
    }

    public function testDeclaringNoPatchesAtAllIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(0)->isVacuous());
    }

    public function testCheckingSomePatchesIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(1, ['drupal/acme "Fix"'])->isVacuous());
    }

    public function testTheSingularReadsAsEnglish(): void
    {
        self::assertSame(['drupatch: checked 1 patch'], $this->coverage(1)->lines());
    }

    public function testOneHeldBackPatchReadsAsEnglish(): void
    {
        self::assertSame('drupatch: checked 2 patches; held back 1', $this->coverage(2, ['drupal/a "One"'])->lines()[0]);
    }

    /**
     * @param list<string> $heldBackPatches
     */
    private function coverage(int $patches, array $heldBackPatches = []): Coverage
    {
        return new Coverage($patches, $heldBackPatches);
    }
}
