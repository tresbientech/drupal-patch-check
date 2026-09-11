<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Source;

/**
 * The order merge requests on one issue are offered in: the ones aimed at the release this site runs first.
 *
 * @phpstan-type Candidate array{iid: string, title: string, target: string, draft: bool, state: string, updated: string}
 */
class Ranking
{
    /**
     * Orders the candidates against an installed release: its own branch first, then the branch its major line develops on, then the rest. A draft goes last inside its rank, and the newest push wins a tie.
     *
     * @param list<Candidate> $candidates
     *
     * @return list<Candidate>
     */
    public static function order(array $candidates, string $version): array
    {
        $branches = self::branches($version);
        \usort($candidates, static function (array $a, array $b) use ($branches): int {
            $fit = [self::rank($a['target'], $branches), $a['draft']] <=> [self::rank($b['target'], $branches), $b['draft']];

            // The stamps are ISO 8601, so the later string is the later push.
            return 0 !== $fit ? $fit : \strcmp($b['updated'], $a['updated']);
        });

        return $candidates;
    }

    /**
     * Which candidate a run takes without being told: the first that is not a draft, and the first of all when every one is.
     *
     * @param list<Candidate> $ordered
     */
    public static function best(array $ordered): int
    {
        foreach ($ordered as $i => $candidate) {
            if (!$candidate['draft']) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * The branches a release is developed on, most specific first.
     *
     * drupal.org publishes a contrib release either as `M.N.P`, whose branch
     * is `M.N.x`, or as `8.x-M.N`, which composer writes as `M.N.0` and whose
     * branch is `8.x-M.x`. A `.0` release reads both ways, its own spelling
     * first. The same rule decides tag names in `_shared/go/patchcheck`.
     *
     * @return list<string>
     */
    public static function branches(string $version): array
    {
        $version = \trim($version);
        if (1 !== \preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $found)) {
            return [];
        }
        $branches = [$found[1].'.'.$found[2].'.x', $found[1].'.x'];
        if ('0' === $found[3]) {
            $branches[] = '8.x-'.$found[1].'.x';
            $branches[] = '7.x-'.$found[1].'.x';
        }

        return $branches;
    }

    /**
     * How well one target branch fits, lowest first.
     *
     * @param list<string> $branches
     */
    private static function rank(string $target, array $branches): int
    {
        $at = \array_search($target, $branches, true);

        return false === $at ? \count($branches) : $at;
    }
}
