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
    public const COMMAND = Report::COMMAND;

    /** Row indent, the mark and its space, then the verdict column. */
    private const DETAIL_INDENT = '                  ';

    /** Longest list before the tail becomes an ellipsis. */
    private const MAX_ROWS = 20;

    /**
     * The statuses the headline counts, worst first, in the words the report's tally uses.
     */
    private const MENTION_ORDER = [PatchRow::BROKEN_SYNTAX, 'conflicts', 'unknown', 'merged'];

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

        $lines = ['<info>'.Report::LABEL.'</info>: '.self::headline($rows)];
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
            $lines[] = \sprintf('  %s %-9s %s %s  %s', Report::marked($row->status()), $row->verdict, $row->package, $row->version, $row->label());
            // An unclear row is the one case where the verdict alone
            // says nothing.
            if ('' !== $row->reason()) {
                $lines[] = self::DETAIL_INDENT.$row->reason();
            }
            foreach ($row->syntaxErrors as $error) {
                $lines[] = self::DETAIL_INDENT.$row->failureMode.': '.$error;
            }
            // An applying row is here for what it references, so that
            // is its one line.
            if (PatchRow::APPLIES === $row->status()) {
                $lines[] = self::DETAIL_INDENT.self::coreLine($row);
            }
        }

        $lines[] = '  run `'.self::COMMAND.'` for the detail, or `--target <version>` before a core upgrade';
        foreach (Report::nextStepLines($plan->counts) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The warnings this hook prints: what a scan row says about a package
     * it has a row for, and what the plan says about the run itself.
     *
     * @return list<string>
     */
    private static function worthPrinting(Plan $plan): array
    {
        $out = $plan->warnings;
        foreach ($plan->packages() as $package) {
            if ('' !== ($note = $plan->rowNotes[$package] ?? '')) {
                $out[] = $package.' '.$note;
            }
        }

        return $out;
    }

    /**
     * The first flagged core reference, with the count of the others.
     */
    private static function coreLine(PatchRow $row): string
    {
        $rest = $row->flaggedCoreReferences() - 1;

        return (Report::coreReferenceLines($row)[0] ?? '').($rest > 0 ? ' (+'.$rest.' more)' : '');
    }

    /**
     * The first line: what is left to decide, not what composer already applied.
     *
     * @param list<PatchRow> $rows
     */
    private static function headline(array $rows): string
    {
        $counts = [];
        $referencing = 0;
        foreach ($rows as $row) {
            if (PatchRow::APPLIES === $row->status()) {
                ++$referencing;
                continue;
            }
            $counts[$row->status()] = ($counts[$row->status()] ?? 0) + 1;
        }
        $parts = [];
        foreach (self::MENTION_ORDER as $status) {
            if (($counts[$status] ?? 0) > 0) {
                $parts[] = $counts[$status].' '.$status;
                unset($counts[$status]);
            }
        }
        foreach ($counts as $status => $count) {
            $parts[] = $count.' '.$status;
        }
        if ($referencing > 0) {
            $parts[] = $referencing.' with core references to check';
        }

        return \implode(', ', $parts).' after this update';
    }
}
