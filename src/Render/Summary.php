<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Outcome;
use Tresbien\Drupatch\Plan\PatchRow;
use Tresbien\Drupatch\Plan\Plan;

/**
 * One object a scheduled job can read without walking every row: what the
 * run was about, what it found, and which packages are behind each
 * finding.
 *
 * It is built from the rows the table renders, so the two cannot disagree.
 */
final class Summary
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Plan $plan, bool $strict = false, bool $vacuous = false): array
    {
        $counts = [];
        foreach ($plan->patches as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }
        \ksort($counts);

        $summary = [
            'target_core' => $plan->targetCore,
            'target_is_installed' => $plan->targetIsInstalled,
            'counts' => $counts,
            'needs_reroll' => self::packagesWith($plan, PatchRow::NEEDS_REROLL),
            'unclear' => self::packagesWith($plan, PatchRow::UNKNOWN),
            'shipped' => self::packagesWith($plan, PatchRow::SHIPPED),
            'blocked' => $plan->noRelease,
            'decided_by' => self::sources($plan),
            'exit_code' => Outcome::of($plan, $strict, $vacuous),
        ];
        if ('' !== $plan->targetFrom) {
            $summary['target_from'] = $plan->targetFrom;
        }

        return $summary;
    }

    /**
     * How many rows each source decided, so a reader can tell an answer
     * composer made from one the service's release table made.
     *
     * @return array<string, int>
     */
    private static function sources(Plan $plan): array
    {
        $counts = [];
        foreach ($plan->patches as $row) {
            if ('' !== $row->decidedBy) {
                $counts[$row->decidedBy] = ($counts[$row->decidedBy] ?? 0) + 1;
            }
        }
        \ksort($counts);

        return $counts;
    }

    /**
     * The packages carrying at least one row of a verdict, in plan order
     * and named once each.
     *
     * @return list<string>
     */
    private static function packagesWith(Plan $plan, string $verdict): array
    {
        $seen = [];
        foreach ($plan->patches as $row) {
            if ($row->verdict === $verdict) {
                $seen[$row->package] = true;
            }
        }

        return \array_keys($seen);
    }
}
