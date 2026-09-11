<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Source\Ranking;

#[CoversClass(Ranking::class)]
class RankingTest extends TestCase
{
    /**
     * @return array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}
     */
    private static function candidate(string $iid, string $target, bool $draft = false, string $updated = '2026-01-01T00:00:00Z'): array
    {
        return ['iid' => $iid, 'title' => 'Fix '.$iid, 'target' => $target, 'draft' => $draft, 'state' => 'opened', 'updated' => $updated];
    }

    /**
     * @param list<array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}> $candidates
     *
     * @return list<string>
     */
    private static function ordered(array $candidates, string $version = '6.2.9'): array
    {
        return \array_column(Ranking::order($candidates, $version), 'iid');
    }

    public function testTheReleasesOwnBranchComesFirst(): void
    {
        $found = self::ordered([
            self::candidate('1', '7.x'),
            self::candidate('2', '6.x'),
            self::candidate('3', '6.2.x'),
        ]);

        self::assertSame(['3', '2', '1'], $found);
    }

    // A project doing its work on a dev branch is still offered.
    public function testABranchTheReleaseDoesNotKnowIsStillListed(): void
    {
        self::assertSame(['1'], self::ordered([self::candidate('1', '11.x')]));
    }

    public function testADraftSortsBehindItsRankAndIsNeverTheDefault(): void
    {
        $ordered = Ranking::order([
            self::candidate('1', '6.2.x', draft: true),
            self::candidate('2', '6.2.x'),
        ], '6.2.9');

        self::assertSame(['2', '1'], \array_column($ordered, 'iid'));
        self::assertSame(0, Ranking::best($ordered));
    }

    public function testTheNewestPushWinsATie(): void
    {
        $found = self::ordered([
            self::candidate('1', '6.2.x', updated: '2026-01-01T00:00:00Z'),
            self::candidate('2', '6.2.x', updated: '2026-09-09T00:00:00Z'),
        ]);

        self::assertSame(['2', '1'], $found);
    }

    // Every candidate unfinished still gets a default, since the list is
    // offered either way.
    public function testTheFirstIsTheDefaultWhenEveryOneIsADraft(): void
    {
        $ordered = Ranking::order([
            self::candidate('1', '11.x', draft: true),
            self::candidate('2', '6.2.x', draft: true),
        ], '6.2.9');

        self::assertSame(0, Ranking::best($ordered));
        self::assertSame('2', $ordered[0]['iid']);
    }

    public function testASemverReleaseNamesItsMinorAndMajorBranch(): void
    {
        self::assertSame(['6.2.x', '6.x'], Ranking::branches('6.2.9'));
        self::assertSame(['11.4.x', '11.x'], Ranking::branches('11.4.5'));
    }

    // drupal.org publishes `8.x-1.13` and composer writes it `1.13.0`; the
    // branch holding it is `8.x-1.x`.
    public function testALegacyReleaseNamesTheBranchItsTagLivesOn(): void
    {
        self::assertSame(['1.13.x', '1.x', '8.x-1.x', '7.x-1.x'], Ranking::branches('1.13.0'));
        self::assertSame(['2.0.x', '2.x', '8.x-2.x', '7.x-2.x'], Ranking::branches('2.0.0-beta4'));
    }

    // core 11.4.0 and webform 6.2.0 are semver releases that happen to end in .0.
    public function testAPointZeroSemverReleaseKeepsItsOwnBranchFirst(): void
    {
        self::assertSame(['11.4.x', '11.x', '8.x-11.x', '7.x-11.x'], Ranking::branches('11.4.0'));
        self::assertSame(['1', '2', '3'], self::ordered([
            self::candidate('3', '8.x-11.x'),
            self::candidate('2', '11.x'),
            self::candidate('1', '11.4.x'),
        ], '11.4.0'));
        self::assertSame(['2', '1'], self::ordered([
            self::candidate('1', '7.x-6.x'),
            self::candidate('2', '6.2.x'),
        ], '6.2.0'));
    }

    public function testAVersionThatNamesNoBranchRanksEveryCandidateAlike(): void
    {
        self::assertSame([], Ranking::branches('dev-main'));
        self::assertSame(['2', '1'], self::ordered([
            self::candidate('1', '6.2.x', updated: '2026-01-01T00:00:00Z'),
            self::candidate('2', '11.x', updated: '2026-09-09T00:00:00Z'),
        ], 'dev-main'));
    }
}
