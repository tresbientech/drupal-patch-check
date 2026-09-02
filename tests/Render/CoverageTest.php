<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Coverage;

final class CoverageTest extends TestCase
{
    private const FORK = 'not a drupal.org project';

    private const HOST = 'the service does not fetch from that host';

    private const CAP = 'above the 16 MB cap';

    // One package, one line. Six lines naming six patches on a package
    // the run never touched is detail nobody acts on.
    public function testAPackageIsNamedOnceWithItsPatchCount(): void
    {
        $coverage = $this->coverage(53, [
            $this->skip('acquia/cohesion', 'PHP warning fix'),
            $this->skip('acquia/cohesion', 'Fix compatibility with ECA'),
            $this->skip('acquia/cohesion', 'Tmgmt issue fix'),
        ]);

        self::assertSame(
            ['acquia/cohesion 7.6.1   3 patches skipped (not a drupal.org project)'],
            $coverage->unjudged([]),
        );
    }

    public function testTwoPackagesGetALineEach(): void
    {
        $coverage = $this->coverage(50, [
            $this->skip('acquia/cohesion', 'One'),
            $this->skip('drupal/nouislider_js', 'Two'),
            $this->skip('acquia/cohesion', 'Three'),
        ]);

        self::assertSame([
            'acquia/cohesion 7.6.1   2 patches skipped (not a drupal.org project)',
            'drupal/nouislider_js 1.0.0   1 patch skipped (not a drupal.org project)',
        ], $coverage->unjudged([]));
    }

    // The reason is the patch's, not the package's, so one package can
    // take two lines when its patches were skipped for different reasons.
    public function testOnePackageWithTwoReasonsGetsALinePerReason(): void
    {
        $coverage = $this->coverage(50, [
            $this->skip('drupal/webform', 'From our gitlab', self::HOST),
            $this->skip('drupal/webform', 'A fork of it', self::FORK),
            $this->skip('drupal/webform', 'From our other gitlab', self::HOST),
        ]);

        self::assertSame([
            'drupal/webform 6.2.9   2 patches skipped (the service does not fetch from that host)',
            'drupal/webform 6.2.9   1 patch skipped (not a drupal.org project)',
        ], $coverage->unjudged([]));
    }

    // A package the site declares a patch for but does not install has
    // no release to name, so the line carries the name alone.
    public function testAPackageTheSiteDoesNotInstallIsNamedWithoutAVersion(): void
    {
        $coverage = $this->coverage(50, [$this->skip('acme/gone', 'In-house fix')]);

        self::assertSame(
            ['acme/gone   1 patch skipped (not a drupal.org project)'],
            $coverage->unjudged([]),
        );
    }

    // A package already in the table says what it skipped inside its own
    // block, so its name is printed once.
    public function testAPackageTheTableAlreadyShowsGetsNoLineOfItsOwn(): void
    {
        $coverage = $this->coverage(50, [$this->skip('drupal/webform', 'From our gitlab', self::HOST)]);

        self::assertSame([], $coverage->unjudged(['drupal/webform']));
        self::assertSame(
            ['1 patch skipped (the service does not fetch from that host)'],
            $coverage->notesFor('drupal/webform'),
        );
    }

    public function testAPackageWithNothingHeldBackHasNothingToSay(): void
    {
        self::assertSame([], $this->coverage(50)->notesFor('drupal/webform'));
    }

    // Skipped and withheld are different states: one was never sent, the
    // other was sent without its text.
    public function testWhatWasSkippedAndWhatWasSentWithoutItsTextBothShow(): void
    {
        $coverage = $this->coverage(
            50,
            [$this->skip('drupal/webform', 'From our gitlab', self::HOST)],
            [$this->withhold('drupal/webform', 'Huge', 'patches/huge.patch')],
        );

        self::assertSame([
            '1 patch skipped (the service does not fetch from that host)',
            '1 patch text not sent (above the 16 MB cap)',
        ], $coverage->notesFor('drupal/webform'));
    }

    public function testTwoWithheldTextsShareTheirLine(): void
    {
        $coverage = $this->coverage(50, [], [
            $this->withhold('drupal/webform', 'Huge', 'patches/huge.patch'),
            $this->withhold('drupal/webform', 'Also huge', 'patches/also.patch'),
        ]);

        self::assertSame(
            ['2 patch texts not sent (above the 16 MB cap)'],
            $coverage->notesFor('drupal/webform'),
        );
    }

    // The report tells a lost file from a withheld one by this list, so
    // it carries the declared path rather than the title.
    public function testTheWithheldSourcesAreNamedByTheirDeclaredPath(): void
    {
        $coverage = $this->coverage(50, [], [
            $this->withhold('drupal/webform', 'Huge', 'patches/huge.patch'),
        ]);

        self::assertSame(['patches/huge.patch'], $coverage->withheld());
    }

    public function testNoPatchTitleReachesTheOutput(): void
    {
        $coverage = $this->coverage(53, [$this->skip('acquia/cohesion', 'Page builder lock logic')]);

        self::assertStringNotContainsString('Page builder lock logic', \implode("\n", $coverage->unjudged([])));
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
     * @return array{package: string, title: string, source: string, reason: string}
     */
    private function withhold(string $package, string $title, string $source, string $reason = self::CAP): array
    {
        return ['package' => $package, 'title' => $title, 'source' => $source, 'reason' => $reason];
    }

    /**
     * @param list<array{package: string, title: string, reason: string}>                 $skipped
     * @param list<array{package: string, title: string, source: string, reason: string}> $unsent
     */
    private function coverage(int $patches, array $skipped = [], array $unsent = []): Coverage
    {
        return new Coverage($patches, $skipped, $unsent, [
            'acquia/cohesion' => '7.6.1',
            'drupal/nouislider_js' => '1.0.0',
            'drupal/webform' => '6.2.9',
            'drupal/a' => '1.0.0',
        ]);
    }
}
