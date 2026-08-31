<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * The lines the post-update hook prints: the verdict tally, the patches that need attention, and any caveat on the counts.
 */
class HookReport
{
    /** The command the hook points at for anything it does not print. */
    public const COMMAND = 'composer drupal-patch-check';

    /** Row indent, the mark and its space, then the verdict column. */
    private const DETAIL_INDENT = '                  ';

    /** Longest list before the tail becomes an ellipsis. */
    private const MAX_ROWS = 20;

    /**
     * What each verdict means to a person reading the hook, worst first.
     */
    private const MENTION_ORDER = [
        'conflicts' => 'need a re-roll',
        'unknown' => 'unclear',
        'merged' => 'can go',
    ];

    /**
     * @return list<string>
     */
    public static function lines(Plan $plan): array
    {
        $rows = $plan->worthMentioning();
        // Same rule as the report: a warning about a package carrying no
        // patch is not this hook's business.
        $warnings = self::worthPrinting($plan);
        // Composer applies a package's patches during the update, and
        // this hook runs after it. A patch that still applies is
        // something composer has already proved; what composer cannot
        // say is that a patch can be deleted.
        if ([] === $rows) {
            return [];
        }

        $lines = ['<info>drupatch</info>: '.self::headline($rows)];
        foreach ($warnings as $warning) {
            $lines[] = '  <comment>! '.$warning.'</comment>';
        }

        $shown = 0;
        foreach ($rows as $row) {
            if (self::MAX_ROWS === $shown) {
                $lines[] = '  …';
                break;
            }
            ++$shown;
            $lines[] = \sprintf('  %s %-9s %s %s  %s', Report::marked($row->verdict), $row->verdict, $row->package, $row->version, $row->label());
            // An unclear row is the one case where the verdict alone
            // says nothing.
            if ('' !== $row->reason()) {
                $lines[] = self::DETAIL_INDENT.$row->reason();
            }
        }

        $lines[] = '  run `'.self::COMMAND.'` for the detail, or `--target <version>` before a core upgrade';
        foreach (Report::nextStepLines($plan->counts) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The warnings this hook prints: those about a package it names a row for, and those about the run rather than about a package.
     *
     * @return list<string>
     */
    private static function worthPrinting(Plan $plan): array
    {
        $named = \array_merge($plan->packages(), $plan->noRelease);
        $out = [];
        foreach ($plan->warnings as $warning) {
            $about = '';
            foreach ($named as $package) {
                if (\str_starts_with($warning, $package.' ')) {
                    $about = $package;
                    break;
                }
            }
            if ('' === $about || \in_array($about, $plan->packages(), true)) {
                $out[] = $warning;
            }
        }

        return $out;
    }

    /**
     * The first line: what is left to decide, not what composer already applied.
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

        return \implode(', ', $parts).' after this update';
    }
}
