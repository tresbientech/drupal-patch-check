<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Plan\PatchRow;
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

    /**
     * What each verdict means to a person reading the hook, worst first.
     * still-needed is absent: composer applied those during the update.
     */
    private const MENTION_ORDER = [
        'needs-reroll' => 'need a re-roll',
        'unknown' => 'unclear',
        'shipped' => 'can go',
    ];

    /**
     * @return list<string>
     */
    public static function lines(Plan $plan): array
    {
        $rows = $plan->worthMentioning();
        // Composer applies a package's patches during the update, and
        // this hook runs after it. A patch that still applies is
        // something composer has already proved, so saying it again is
        // noise; what composer cannot say is that a patch can be deleted.
        if ([] === $rows && !$plan->isBlocked() && [] === $plan->warnings) {
            return [];
        }

        $lines = ['<info>drupatch</info>: '.self::headline($rows)];
        foreach ($plan->warnings as $warning) {
            $lines[] = '  <comment>! '.$warning.'</comment>';
        }

        $shown = 0;
        foreach ($rows as $row) {
            if (self::MAX_ROWS === $shown) {
                $lines[] = '  …';
                break;
            }
            ++$shown;
            $lines[] = \sprintf('  %-13s %s %s  %s', $row->verdict, $row->package, $row->version, $row->label());
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
        $lines[] = '  run `'.self::COMMAND.'` for the detail, or `--target <version>` before a core upgrade';

        return $lines;
    }

    /**
     * The first line: what is left to decide, not what composer already
     * applied.
     *
     * @param list<PatchRow> $rows
     */
    private static function headline(array $rows): string
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }
        $parts = [];
        foreach (self::MENTION_ORDER as $verdict => $phrase) {
            if (($counts[$verdict] ?? 0) > 0) {
                $parts[] = $counts[$verdict].' '.$phrase;
                unset($counts[$verdict]);
            }
        }
        foreach ($counts as $verdict => $count) {
            $parts[] = $count.' '.$verdict;
        }
        // The blocked line below names the packages, so the headline
        // says only that no patch is waiting on a person.
        if ([] === $parts) {
            return 'no patch needs a decision';
        }

        return \implode(', ', $parts).' after this update';
    }
}
