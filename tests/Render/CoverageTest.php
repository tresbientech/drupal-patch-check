<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;

/**
 * What a run did not judge: the patches it never sent, and the ones it sent without their text.
 *
 * @phpstan-import-type Coverage from \TresBienTech\Drupatch\Read\Run
 */
class CoverageTest extends TestCase
{
    use PlanFactory;

    private const FORK = 'not a drupal.org project';

    private const HOST = 'the service does not fetch from that host';

    private const CAP = 'above the 16 MB cap';

    // One package, one line. Six lines naming six patches on a package
    // the run never touched is detail nobody acts on.
    public function testAPackageIsNamedOnceWithItsPatchCount(): void
    {
        $coverage = $this->coverage(53, [
            $this->skip('acme/pagebuilder', 'PHP warning fix'),
            $this->skip('acme/pagebuilder', 'Fix compatibility with ECA'),
            $this->skip('acme/pagebuilder', 'Tmgmt issue fix'),
        ]);

        self::assertSame(['acme/pagebuilder 7.6.1   3 patches skipped (not a drupal.org project)'], $this->unjudged($coverage));
    }

    public function testTwoPackagesGetALineEach(): void
    {
        $coverage = $this->coverage(50, [
            $this->skip('acme/pagebuilder', 'One'),
            $this->skip('drupal/nouislider_js', 'Two'),
            $this->skip('acme/pagebuilder', 'Three'),
        ]);

        self::assertSame([
            'acme/pagebuilder 7.6.1   2 patches skipped (not a drupal.org project)',
            'drupal/nouislider_js 1.0.0   1 patch skipped (not a drupal.org project)',
        ], $this->unjudged($coverage));
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
        ], $this->unjudged($coverage));
    }

    // A package the site declares a patch for but does not install has
    // no release to give, so the line carries the name alone.
    public function testAPackageTheSiteDoesNotInstallIsNamedWithoutAVersion(): void
    {
        $coverage = $this->coverage(50, [$this->skip('acme/gone', 'In-house fix')]);

        self::assertSame(['acme/gone   1 patch skipped (not a drupal.org project)'], $this->unjudged($coverage));
    }

    // A package already in the table says what it skipped inside its own
    // table, so its name is printed once.
    public function testAPackageTheTableAlreadyShowsGetsNoLineOfItsOwn(): void
    {
        $coverage = $this->coverage(50, [$this->skip('drupal/webform', 'From our gitlab', self::HOST)]);

        self::assertSame([], $this->unjudged($coverage, [$this->row()]));
        self::assertSame(['1 patch skipped (the service does not fetch from that host)'], $this->notes($coverage));
    }

    // On 2.x the reason is left to composer patches-doctor, so the note
    // counts the patch and gives no reason.
    public function testASkipWithNoReasonPrintsNoParentheses(): void
    {
        $coverage = $this->coverage(50, [$this->skip('drupal/webform', 'From our gitlab', '')]);

        self::assertSame(['1 patch skipped'], $this->notes($coverage));
    }

    public function testAPackageWithNothingHeldBackHasNothingToSay(): void
    {
        self::assertSame([], $this->notes($this->coverage(50)));
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
        ], $this->notes($coverage));
    }

    public function testTwoWithheldTextsShareTheirLine(): void
    {
        $coverage = $this->coverage(50, [], [
            $this->withhold('drupal/webform', 'Huge', 'patches/huge.patch'),
            $this->withhold('drupal/webform', 'Also huge', 'patches/also.patch'),
        ]);

        self::assertSame(['2 patch texts not sent (above the 16 MB cap)'], $this->notes($coverage));
    }

    // The report tells a lost file from a withheld one by its declared
    // path, so a file the run held back is not called lost.
    public function testTheWithheldSourcesAreNamedByTheirDeclaredPath(): void
    {
        $coverage = $this->coverage(50, [], [$this->withhold('drupal/webform', 'Huge', 'patches/huge.patch')]);
        $plan = $this->planFrom(['missing_files' => ['patches/huge.patch', 'patches/lost.patch'], 'patches' => [$this->row()]]);

        self::assertContains('  patch text not sent for: patches/lost.patch', Report::print(Report::check($plan, $coverage, null, null, 100, [])));
    }

    public function testNoPatchTitleReachesTheOutput(): void
    {
        $coverage = $this->coverage(53, [$this->skip('acme/pagebuilder', 'Page builder lock logic')]);

        self::assertStringNotContainsString('Page builder lock logic', \implode("\n", $this->report($coverage)));
    }

    public function testDeclaringPatchesAndCheckingNoneIsVacuous(): void
    {
        self::assertStringContainsString('no patch could be checked', $this->report($this->coverage(0, [$this->skip('drupal/a', 'Fix')]))[0]);
    }

    public function testDeclaringNoPatchesAtAllIsNotVacuous(): void
    {
        self::assertStringNotContainsString('no patch could be checked', $this->report($this->coverage(0))[0]);
    }

    public function testCheckingSomePatchesIsNotVacuous(): void
    {
        self::assertStringNotContainsString('no patch could be checked', $this->report($this->coverage(1, [$this->skip('drupal/a', 'Fix')]))[0]);
    }

    /**
     * The report for a plan holding these rows, printed.
     *
     * @param Coverage                   $coverage
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function report(array $coverage, array $rows = []): array
    {
        return Report::print(Report::check($this->planFrom(['patches' => $rows]), $coverage, null, null, 100, []));
    }

    /**
     * The skipped packages the report lists, without their indent and colour.
     *
     * @param Coverage                   $coverage
     * @param list<array<string, mixed>> $rows     the rows the table has, whose packages are not listed again
     *
     * @return list<string>
     */
    private function unjudged(array $coverage, array $rows = []): array
    {
        return self::caveats($this->report($coverage, $rows), '  ');
    }

    /**
     * What the report says under drupal/webform's rows about the patches it did not judge.
     *
     * @param Coverage $coverage
     *
     * @return list<string>
     */
    private function notes(array $coverage): array
    {
        return self::caveats($this->report($coverage, [$this->row()]), '        ');
    }

    /**
     * The caveat lines at one indent, without the indent and the colour.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function caveats(array $lines, string $indent): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (1 === \preg_match('/^'.$indent.'<comment>(.+)<\/comment>$/', $line, $match)) {
                $out[] = $match[1];
            }
        }

        return $out;
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
     *
     * @return Coverage
     */
    private function coverage(int $patches, array $skipped = [], array $unsent = []): array
    {
        return ['checked' => $patches, 'skipped' => $skipped, 'unsent' => $unsent, 'versions' => [
            'acme/pagebuilder' => '7.6.1',
            'drupal/nouislider_js' => '1.0.0',
            'drupal/webform' => '6.2.9',
            'drupal/a' => '1.0.0',
        ]];
    }
}
