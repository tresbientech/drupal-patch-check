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
     * @param int $width the terminal width the report is laid out for
     *
     * @return list<string>
     */
    public static function lines(Plan $plan, int $width = 100): array
    {
        $detail = Budget::detailIndent();
        $trailing = 0;
        foreach ($plan->patches as $patch) {
            $trailing = \max($trailing, \mb_strlen(self::fileName($patch)));
        }
        $titleWidth = Budget::title($width, $trailing);
        $total = \count($plan->patches);
        $lines = [\sprintf(
            '<info>Drupal Code Query</info>: %d patch%s %s',
            $total,
            1 === $total ? '' : 'es',
            $plan->scenario()
        ), ''];

        $grouped = self::byPackage($plan);
        $placed = self::warningsByPackage($plan->warnings, \array_keys($grouped));
        $loose = self::aboutNoPackage($plan->warnings, \array_merge($plan->packages(), $plan->noRelease));

        foreach ($loose as $warning) {
            $lines[] = self::warning($warning);
        }
        if ([] !== $loose) {
            $lines[] = '';
        }

        $first = true;
        foreach ($grouped as $package => $rows) {
            if (!$first) {
                $lines[] = '';
            }
            $first = false;
            foreach ($placed[$package] ?? [] as $warning) {
                $lines[] = self::warning($warning);
            }
            $lines[] = '  '.self::heading($rows[0]).'   '.self::packageTally($rows);
            foreach ($rows as $row) {
                $lines[] = \rtrim(\sprintf(
                    '    %s %-9s %s  %s',
                    Verdict::marked($row->verdict),
                    $row->verdict,
                    Budget::pad(Budget::fit($row->label(), $titleWidth), $titleWidth),
                    self::fileName($row),
                ));
                if ('' !== $row->reason()) {
                    $lines[] = $detail.$row->reason();
                }
                // What a re-roll is up against, so the size of the work
                // is readable without opening the patch.
                if ('' !== $row->firstFailure()) {
                    $lines[] = $detail.$row->firstFailure();
                }
                // The verdict stands; this says the patch needed a
                // looser reading than git apply gives.
                if ('' !== $row->strictRefused) {
                    $lines[] = $detail.$row->strictRefused;
                }
                // The row may be a consequence of the named patch, so
                // that one is the thing to fix first.
                if ('' !== $row->judgedWithout) {
                    $lines[] = $detail.'judged without "'.$row->judgedWithout.'", which did not apply';
                }
            }
        }

        $lines[] = '';
        $lines[] = '  patches: '.self::tally($plan->counts);

        if ([] !== $plan->missingFiles) {
            $lines[] = '  patch text not sent for: '.\implode(', ', $plan->missingFiles);
        }

        return $lines;
    }

    /**
     * The whole report in the order it is printed: the rows, the files a
     * re-roll wrote, then what to run next.
     *
     * The footer is last because that is where a reader's eye stops when
     * the output does.
     *
     * @param list<WrittenFile> $written
     *
     * @return list<string>
     */
    public static function report(Plan $plan, array $written, int $width = 100): array
    {
        return \array_merge(
            self::lines($plan, $width),
            self::written($written),
            self::footer($plan),
        );
    }

    /**
     * What to run next, empty when there is nothing to run.
     *
     * @return list<string>
     */
    public static function footer(Plan $plan): array
    {
        $lines = NextSteps::lines($plan->counts);

        return [] === $lines ? [] : \array_merge([''], $lines);
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
     * The patch file as a reader would name it: the last segment of its
     * path or URL, without a query string.
     *
     * A patch with no title of its own is already labelled by its
     * source, so a column repeating it would say the same thing twice.
     */
    private static function fileName(PatchRow $row): string
    {
        if ('' === $row->title || '' === $row->source) {
            return '';
        }
        $path = $row->source;
        $query = \strpos($path, '?');
        if (false !== $query) {
            $path = \substr($path, 0, $query);
        }
        $cut = \strrpos($path, '/');

        return Budget::fit(false === $cut ? $path : \substr($path, $cut + 1), Budget::TRAILING_MAX);
    }

    /**
     * Patches under their package, in the order the site declares them.
     *
     * That is the order composer applies them, and a patch judged
     * without an earlier one cites that earlier one by title. Any other
     * order can print the cited patch below the row citing it.
     *
     * A package appears once, at its first declaration. The order is the
     * document's, so two runs on one plan stay byte-identical and a CI
     * log can be diffed against the last one.
     *
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
     * One warning, marked so it reads as a caveat on the rows near it.
     */
    private static function warning(string $warning): string
    {
        return '  <comment>! '.$warning.'</comment>';
    }

    /**
     * Warnings grouped under the package each one is about.
     *
     * A warning about a package opens with its name, which is the only
     * handle the plugin has: the sentence is built by the service and
     * arrives as text.
     *
     * @param list<string> $warnings
     * @param list<string> $packages
     *
     * @return array<string, non-empty-list<string>>
     */
    private static function warningsByPackage(array $warnings, array $packages): array
    {
        $out = [];
        foreach ($warnings as $warning) {
            foreach ($packages as $package) {
                if (\str_starts_with($warning, $package.' ')) {
                    $out[$package][] = $warning;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The warnings that name no package at all.
     *
     * One of those is about the run rather than about a package, so it
     * leads the report and says the counts below cannot be trusted.
     *
     * The names checked are every patched package plus every blocked
     * one, which together are the packages a warning can be about. So a
     * warning naming a blocked package that carries no patch matches
     * here and is dropped: the report is about patches, and that package
     * has none.
     *
     * @param list<string> $warnings
     * @param list<string> $packages
     *
     * @return list<string>
     */
    private static function aboutNoPackage(array $warnings, array $packages): array
    {
        $out = [];
        foreach ($warnings as $warning) {
            foreach ($packages as $package) {
                if (\str_starts_with($warning, $package.' ')) {
                    continue 2;
                }
            }
            $out[] = $warning;
        }

        return $out;
    }

    /**
     * What one package's patches came to, worst verdict first.
     *
     * @param non-empty-list<PatchRow> $rows
     */
    private static function packageTally(array $rows): string
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }
        \uksort($counts, static fn (string $a, string $b): int => [Verdict::rank($a), $a] <=> [Verdict::rank($b), $b]);

        return self::tally($counts);
    }

    /**
     * The package line: the release the verdicts are about, and the one
     * the lock holds when they differ.
     */
    private static function heading(PatchRow $row): string
    {
        // Nothing was judged, so there is no second release to point at.
        // The heading names the one the lock holds and the rows say why
        // they carry no verdict.
        if ('' === $row->version) {
            return $row->package.' '.$row->installed;
        }
        if (!$row->movesRelease()) {
            return $row->package.' '.('' === $row->installed ? $row->version : $row->installed);
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
