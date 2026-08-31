<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Render\Coverage;

final class CoverageTest extends TestCase
{
    public function testARunThatCheckedEverythingSaysSoInOneLine(): void
    {
        $coverage = $this->coverage(101, 53);

        self::assertSame(['drupatch: checked 101 packages and 53 patches'], $coverage->lines());
        self::assertFalse($coverage->isVacuous());
    }

    public function testHeldBackPackagesAreNamedOnTheTerminal(): void
    {
        $coverage = $this->coverage(101, 53, ['drupal/coder', 'drupal/rat']);

        $lines = $coverage->lines();

        self::assertSame('drupatch: checked 101 packages and 53 patches; held back 2', $lines[0]);
        self::assertSame('  held back  drupal/coder (not a drupal.org release)', $lines[1]);
        self::assertSame('  held back  drupal/rat (not a drupal.org release)', $lines[2]);
    }

    public function testASiteWithNothingFromDrupalOrgIsToldPlainly(): void
    {
        $coverage = $this->coverage(0, 0, ['drupal/webform']);

        self::assertSame(
            ['drupatch: nothing was checked. No installed package comes from drupal.org, so the service has no release to judge against.'],
            $coverage->lines(),
        );
    }

    public function testDeclaringPatchesAndCheckingNoneIsVacuous(): void
    {
        self::assertTrue($this->coverage(3, 0, [], ['drupal/acme "Fix"'])->isVacuous());
    }

    public function testDeclaringNoPatchesAtAllIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(3, 0)->isVacuous());
    }

    public function testCheckingSomePatchesIsNotVacuous(): void
    {
        self::assertFalse($this->coverage(3, 1, [], ['drupal/acme "Fix"'])->isVacuous());
    }

    public function testTheSingularReadsAsEnglish(): void
    {
        self::assertSame(['drupatch: checked 1 package and 1 patch'], $this->coverage(1, 1)->lines());
    }

    /**
     * @param list<string> $heldBackPackages
     * @param list<string> $heldBackPatches
     */
    private function coverage(int $packages, int $patches, array $heldBackPackages = [], array $heldBackPatches = []): Coverage
    {
        return new Coverage($packages, $patches, $heldBackPackages, $heldBackPatches);
    }
}
