<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Render;

use Tresbien\Drupatch\Plan\PatchRow;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Write\WrittenFile;

/**
 * The full report a person reads: patches grouped under their package,
 * the release each verdict is about, and the tallies underneath.
 */
final class Table
{
    /**
     * @return list<string>
     */
    public static function lines(Plan $plan): array
    {
        $total = \count($plan->patches);
        $lines = [\sprintf(
            '<info>Drupal Code Query</info>: %d patch%s against %s',
            $total,
            1 === $total ? '' : 'es',
            $plan->judgedAgainst()
        ), ''];

        foreach ($plan->warnings as $warning) {
            $lines[] = '  <comment>! '.$warning.'</comment>';
        }
        if ([] !== $plan->warnings) {
            $lines[] = '';
        }

        foreach (self::byPackage($plan) as $rows) {
            $lines[] = '  '.self::heading($rows[0]);
            foreach ($rows as $row) {
                $lines[] = \sprintf('    %-13s %s', $row->verdict, $row->label());
                if ('' !== $row->reason()) {
                    $lines[] = '                  '.$row->reason();
                }
                // The verdict stands; this says the patch needed a
                // looser reading than git apply gives.
                if ('' !== $row->strictRefused) {
                    $lines[] = '                  '.$row->strictRefused;
                }
                // The row may be a consequence of the named patch, so
                // that one is the thing to fix first.
                if ('' !== $row->judgedWithout) {
                    $lines[] = '                  judged without "'.$row->judgedWithout.'", which did not apply';
                }
            }
        }

        $lines[] = '';
        // A run narrowed to some packages has no site-wide package
        // tally to quote, so it prints only the patch one.
        if ([] !== $plan->packageCounts) {
            $lines[] = '  packages: '.self::tally($plan->packageCounts);
        }
        $lines[] = '  patches:  '.self::tally($plan->counts);

        if ($plan->isBlocked()) {
            $lines[] = \sprintf('  no release for %s: %s', $plan->against(), \implode(', ', $plan->noRelease));
        }
        if ([] !== $plan->missingFiles) {
            $lines[] = '  patch text not sent for: '.\implode(', ', $plan->missingFiles);
        }

        return $lines;
    }

    /**
     * The files a re-roll wrote, and what still needs a person.
     *
     * @param list<WrittenFile> $written
     *
     * @return list<string>
     */
    public static function written(array $written): array
    {
        if ([] === $written) {
            return [];
        }
        $lines = [''];
        $conflicts = 0;
        foreach ($written as $file) {
            if (!$file->isUsable()) {
                ++$conflicts;
            }
            $lines[] = \sprintf(
                '  wrote %s  (%s%s)',
                $file->path,
                $file->status,
                $file->isUsable() && $file->verified ? ', verified against the release by the server' : ''
            );
        }
        if ($conflicts > 0) {
            $lines[] = \sprintf(
                '  %d re-roll%s left regions to decide; those files are not usable as patches',
                $conflicts,
                1 === $conflicts ? '' : 's'
            );
        }

        return $lines;
    }

    /**
     * @return array<string, non-empty-list<PatchRow>>
     */
    private static function byPackage(Plan $plan): array
    {
        $grouped = [];
        foreach ($plan->patches as $row) {
            $grouped[$row->package][] = $row;
        }

        return $grouped;
    }

    /**
     * The package line: the release the verdicts are about, and the one
     * the lock holds when they differ.
     */
    private static function heading(PatchRow $row): string
    {
        if ('' === $row->version) {
            return \sprintf('%s %s → no release for the target', $row->package, $row->installed);
        }
        if ('' === $row->installed || $row->installed === $row->version) {
            return $row->package.' '.$row->version;
        }

        return \sprintf('%s %s → %s', $row->package, $row->installed, $row->version);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function tally(array $counts): string
    {
        $parts = [];
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$name;
            }
        }

        return [] === $parts ? 'none' : \implode(', ', $parts);
    }
}
