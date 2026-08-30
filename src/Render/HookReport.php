<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Plan\Plan;

/**
 * The lines the post-update hook prints: the verdict tally, the patches
 * that need attention, and the packages that block an upgrade.
 *
 * Patches that still apply and are still needed collapse into the tally.
 */
final class HookReport
{
    /** The command the hook points at for anything it does not print. */
    public const COMMAND = 'composer drupal-patch-check';

    /** Longest list before the tail becomes an ellipsis. */
    private const MAX_ROWS = 20;

    /** Order the tally reads in: what needs work first. */
    private const TALLY_ORDER = ['needs-reroll', 'unknown', 'shipped', 'still-needed'];

    /**
     * @return list<string>
     */
    public static function lines(Plan $plan): array
    {
        if (!$plan->hasPatches()) {
            return [];
        }

        $total = \count($plan->patches);
        $lines = [\sprintf(
            '<info>drupatch</info>: %d patch%s against %s — %s',
            $total,
            1 === $total ? '' : 'es',
            $plan->against(),
            self::tally($plan)
        )];

        foreach ($plan->warnings as $warning) {
            $lines[] = '  <comment>! '.$warning.'</comment>';
        }

        $shown = 0;
        foreach ($plan->worthMentioning() as $row) {
            if (self::MAX_ROWS === $shown) {
                $lines[] = '  …';
                break;
            }
            ++$shown;
            $lines[] = \sprintf('  %-13s %s %s  %s', $row->verdict, $row->package, $row->version, $row->label());
        }
        if ($shown > 0) {
            $lines[] = '  run `'.self::COMMAND.'` for the detail, `--target 11.4.5` to plan an upgrade';
        }

        if ($plan->isBlocked()) {
            $blocking = $plan->noRelease;
            $lines[] = \sprintf(
                '  %d package%s with no release for %s: %s',
                \count($blocking),
                1 === \count($blocking) ? '' : 's',
                $plan->against(),
                \implode(', ', \array_slice($blocking, 0, 10))
            );
        }

        return $lines;
    }

    /**
     * The verdicts as counts, the ones needing work first. A verdict the
     * plugin does not know still appears: it comes from the counts, not
     * from a list of names.
     */
    private static function tally(Plan $plan): string
    {
        $parts = [];
        $counts = $plan->counts;
        foreach (self::TALLY_ORDER as $verdict) {
            if (($counts[$verdict] ?? 0) > 0) {
                $parts[] = $counts[$verdict].' '.$verdict;
                unset($counts[$verdict]);
            }
        }
        foreach ($counts as $verdict => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$verdict;
            }
        }

        return [] === $parts ? 'no verdicts' : \implode(', ', $parts);
    }
}
