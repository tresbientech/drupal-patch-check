<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * The command's output: the table a person reads, the JSON summary a job reads, and the workflow annotations a runner reads.
 */
class Report
{
    /** The command every next-step suggestion is a flag on. */
    public const COMMAND = 'composer drupal-patch-check';

    /** The narrowest terminal the report is laid out for. */
    public const MIN_WIDTH = 80;

    /** Past this a wider terminal only strands the filename column. */
    public const MAX_WIDTH = 120;

    /** Row indent, the mark and its space, the verdict column and its space. */
    public const PREFIX = 16;

    /** The longest filename column, so the narrowest row still fits. */
    public const TRAILING_MAX = 32;

    /** Between the title and the filename. */
    private const GAP = 2;

    /** Below this a title says nothing, so the row runs long instead. */
    private const MIN_TITLE = 24;

    /** What a shortened string ends with. */
    private const ELLIPSIS = '…';

    /** What the footer is introduced by. */
    private const LABEL = 'Next:';

    /**
     * Mark, colour tag and sort rank per verdict, worst first. An unknown verdict gets the fallback and sorts with the work.
     *
     * @var array<string, array{string, string, int}>
     */
    private const VERDICTS = [
        'conflicts' => ['!', 'error', 0],
        'unknown' => ['?', 'comment', 1],
        'applies' => ['·', '', 2],
        'merged' => ['✓', 'info', 3],
    ];

    /** @var array{string, string, int} */
    private const UNRECOGNISED = ['*', 'comment', 1];

    /** The annotation level each verdict is written at. */
    private const ANNOTATION_LEVELS = [
        PatchRow::CONFLICTS => 'error',
        PatchRow::UNKNOWN => 'warning',
        PatchRow::MERGED => 'notice',
    ];

    public static function mark(string $verdict): string
    {
        return (self::VERDICTS[$verdict] ?? self::UNRECOGNISED)[0];
    }

    /**
     * The composer output tag the mark is written with, empty for none.
     */
    public static function tag(string $verdict): string
    {
        return (self::VERDICTS[$verdict] ?? self::UNRECOGNISED)[1];
    }

    /**
     * Where the verdict sorts; lower comes first, so the work is at the top.
     */
    public static function rank(string $verdict): int
    {
        return (self::VERDICTS[$verdict] ?? self::UNRECOGNISED)[2];
    }

    public static function isKnown(string $verdict): bool
    {
        return isset(self::VERDICTS[$verdict]);
    }

    /**
     * The mark ready to print, wrapped in its colour when it has one.
     */
    public static function marked(string $verdict): string
    {
        [$mark, $tag] = self::VERDICTS[$verdict] ?? self::UNRECOGNISED;

        return '' === $tag ? $mark : '<'.$tag.'>'.$mark.'</'.$tag.'>';
    }

    /**
     * A terminal width brought inside the range the report is laid out for.
     */
    public static function clamp(int $width): int
    {
        return \max(self::MIN_WIDTH, \min(self::MAX_WIDTH, $width));
    }

    /**
     * The room a title has, given the total width and the width of the filename column that follows it.
     */
    public static function title(int $width, int $trailing): int
    {
        return \max(self::MIN_TITLE, $width - self::PREFIX - self::GAP - \max(0, $trailing));
    }

    /**
     * The indent a row's detail lines start at, so they sit under the title rather than under the mark.
     */
    public static function detailIndent(): string
    {
        return \str_repeat(' ', self::PREFIX);
    }

    /**
     * `$text` shortened to `$width` characters, ending in an ellipsis when something was cut.
     */
    public static function fit(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (\mb_strlen($text) <= $width) {
            return $text;
        }
        if (1 === $width) {
            return self::ELLIPSIS;
        }

        return \rtrim(\mb_substr($text, 0, $width - 1)).self::ELLIPSIS;
    }

    /**
     * `$text` padded with spaces to `$width` characters.
     */
    public static function pad(string $text, int $width): string
    {
        $short = $width - \mb_strlen($text);

        return $short > 0 ? $text.\str_repeat(' ', $short) : $text;
    }

    /**
     * The whole report in printed order: the rows, the files a re-roll wrote, then what to run next.
     *
     * @param array{written: list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>}>,
     *              refused: list<array{package: string, title: string, path: string, reason: string, lifts: string}>}|null $wrote
     *
     * @return list<string>
     */
    public static function report(Plan $plan, ?array $wrote, int $width = 100): array
    {
        return \array_merge(
            self::lines($plan, $width),
            self::judged($plan),
            self::written(null === $wrote ? [] : $wrote['written']),
            self::refused(null === $wrote ? [] : $wrote['refused']),
            self::footer($plan, $wrote),
        );
    }

    /**
     * The table a person reads: patches grouped under their package, the release each verdict is about, and the tallies underneath.
     *
     * @return list<string>
     */
    public static function lines(Plan $plan, int $width = 100): array
    {
        $detail = self::detailIndent();
        $trailing = 0;
        foreach ($plan->patches as $patch) {
            $trailing = \max($trailing, \mb_strlen(self::fileName($patch)));
        }
        $titleWidth = self::title($width, $trailing);
        $total = \count($plan->patches);
        $lines = [\sprintf(
            '<info>Drupal Code Query</info>: %d patch%s %s',
            $total,
            1 === $total ? '' : 'es',
            $plan->scenario()
        ), ''];

        $grouped = [];
        foreach ($plan->patches as $row) {
            $grouped[$row->package][] = $row;
        }
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
                    self::marked($row->verdict),
                    $row->verdict,
                    self::pad(self::fit($row->label(), $titleWidth), $titleWidth),
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
                // The merge answered these itself, so the patch a
                // person is about to use carries a decision nobody made.
                if ([] !== $row->unioned()) {
                    $lines[] = $detail.self::unionNote(\count($row->unioned()));
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
     * The release the patches were judged against, when the site does not run it.
     *
     * @return list<string>
     */
    public static function judged(Plan $plan): array
    {
        if ($plan->targetIsInstalled || '' === $plan->targetCore || '' === $plan->coreInstalled) {
            return [];
        }

        return ['', \sprintf('  core %s installed, patches judged against %s', $plan->coreInstalled, $plan->targetCore)];
    }

    /**
     * The files a re-roll wrote, and what still needs a person.
     *
     * @param list<array{path: string, status: string, verified: bool, unioned: list<array{file: string, line: int}>}> $written
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
            $usable = 'clean' === $file['status'];
            if (!$usable) {
                ++$conflicts;
            }
            $lines[] = \sprintf(
                '  wrote %s  (%s%s)',
                $file['path'],
                $file['status'],
                $usable && $file['verified'] ? ', verified against the release by the server' : ''
            );
            if ([] !== $file['unioned']) {
                $lines[] = '    '.self::unionNote(\count($file['unioned'])).':';
                foreach ($file['unioned'] as $region) {
                    $lines[] = '      '.$region['file'].':'.$region['line'];
                }
            }
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
     * What the merge decided on its own, in one line.
     */
    public static function unionNote(int $regions): string
    {
        return \sprintf(
            'the merge kept both sides of %d region%s the release and the patch both added to',
            $regions,
            1 === $regions ? '' : 's'
        );
    }

    /**
     * The re-rolls that produced no file, grouped by why, one reason printed once.
     *
     * @param list<array{package: string, title: string, path: string, reason: string, lifts: string}> $refused
     *
     * @return list<string>
     */
    public static function refused(array $refused): array
    {
        if ([] === $refused) {
            return [];
        }
        $groups = [];
        foreach ($refused as $refusal) {
            $groups[$refusal['reason']][] = $refusal;
        }
        \ksort($groups);
        $lines = ['', '  not written:'];
        foreach ($groups as $reason => $items) {
            \usort($items, static fn (array $a, array $b): int => [$a['package'], $a['title']] <=> [$b['package'], $b['title']]);
            foreach ($items as $item) {
                $lines[] = \sprintf('    %s: %s', $item['package'], $item['title']);
            }
            $lines[] = '      '.$reason;
        }

        return $lines;
    }

    /**
     * What to run next, empty when there is nothing to run.
     *
     * @param array{written: list<array<string, mixed>>, refused: list<array{lifts: string}>}|null $wrote
     *
     * @return list<string>
     */
    public static function footer(Plan $plan, ?array $wrote = null): array
    {
        $lines = self::nextStepLines($plan->counts, '  ', $wrote);

        return [] === $lines ? [] : \array_merge([''], $lines);
    }

    /**
     * The commands worth running, worst finding first.
     *
     * @param array<string, int>                              $counts patches per verdict
     * @param array{refused: list<array{lifts: string}>}|null $wrote
     *
     * @return list<array{flag: string, effect: string}>
     */
    public static function nextSteps(array $counts, ?array $wrote = null): array
    {
        $out = [];
        if (null === $wrote) {
            $reroll = $counts[PatchRow::CONFLICTS] ?? 0;
            if ($reroll > 0) {
                $out[] = [
                    'flag' => '--write',
                    'effect' => 1 === $reroll ? 'writes the re-roll' : 'writes the '.$reroll.' re-rolls',
                ];
            }
        } else {
            $forcible = self::lifted($wrote['refused'], '--force');
            if ($forcible > 0) {
                $out[] = [
                    'flag' => '--force',
                    'effect' => 1 === $forcible
                        ? 'replaces the file this run would not overwrite'
                        : 'replaces the '.$forcible.' files this run would not overwrite',
                ];
            }
        }
        $urls = null === $wrote ? 0 : self::lifted($wrote['refused'], '--fix');
        $shipped = $counts[PatchRow::MERGED] ?? 0;
        if ($shipped > 0 || $urls > 0) {
            $out[] = ['flag' => '--fix', 'effect' => self::fixes($shipped, $urls)];
        }

        return $out;
    }

    /**
     * The next-steps footer as printed, empty when there is nothing to run.
     *
     * @param array<string, int>                              $counts patches per verdict
     * @param array{refused: list<array{lifts: string}>}|null $wrote
     *
     * @return list<string>
     */
    public static function nextStepLines(array $counts, string $indent = '  ', ?array $wrote = null): array
    {
        $steps = self::nextSteps($counts, $wrote);
        if ([] === $steps) {
            return [];
        }
        $widest = 0;
        foreach ($steps as $step) {
            $widest = \max($widest, \strlen(self::COMMAND.' '.$step['flag']));
        }
        $lines = [];
        foreach ($steps as $i => $step) {
            $lines[] = $indent
                .(0 === $i ? self::LABEL.'  ' : \str_repeat(' ', \strlen(self::LABEL) + 2))
                .self::pad(self::COMMAND.' '.$step['flag'], $widest)
                .'   '.$step['effect'];
        }

        return $lines;
    }

    /**
     * The `--json` summary a scheduled job reads: what the run was about, what it found, and which packages are behind each finding.
     *
     * @return array<string, mixed>
     */
    public static function summary(Plan $plan, bool $strict = false, bool $vacuous = false): array
    {
        $counts = [];
        foreach ($plan->patches as $row) {
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) + 1;
        }
        \ksort($counts);

        $sources = [];
        foreach ($plan->patches as $row) {
            if ('' !== $row->decidedBy) {
                $sources[$row->decidedBy] = ($sources[$row->decidedBy] ?? 0) + 1;
            }
        }
        \ksort($sources);

        $summary = [
            'target_core' => $plan->targetCore,
            'target_is_installed' => $plan->targetIsInstalled,
            'counts' => $counts,
            'conflicts' => self::packagesWith($plan, PatchRow::CONFLICTS),
            'unclear' => self::packagesWith($plan, PatchRow::UNKNOWN),
            'merged' => self::packagesWith($plan, PatchRow::MERGED),
            'blocked' => $plan->noRelease,
            'decided_by' => $sources,
            'exit_code' => $plan->exitCode($strict, $vacuous),
        ];
        if ('' !== $plan->targetFrom) {
            $summary['target_from'] = $plan->targetFrom;
        }

        return $summary;
    }

    /**
     * One workflow command per row worth annotating, in plan order, anchored to the line declaring the patch.
     *
     * @param string $document the text of the file declaring the patches
     *
     * @return list<string>
     */
    public static function annotations(Plan $plan, string $file, string $document): array
    {
        $out = [];
        foreach ($plan->patches as $row) {
            $level = self::ANNOTATION_LEVELS[$row->verdict] ?? null;
            if (null === $level) {
                continue;
            }
            $message = \sprintf('%s %s %s: %s', $row->verdict, $row->package, $row->version, $row->label());
            if ('' !== $row->firstFailure()) {
                $message .= '; '.$row->firstFailure();
            }
            $out[] = \sprintf(
                '::%s file=%s,line=%d::%s',
                $level,
                $file,
                self::lineOf($document, $row->source),
                \str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message),
            );
        }

        return $out;
    }

    /**
     * The 1-based line declaring this source, or the first line when the document does not carry it.
     */
    public static function lineOf(string $document, string $source): int
    {
        $lines = \preg_split('/\r\n|\r|\n/', $document);
        if ('' === $source || false === $lines) {
            return 1;
        }
        foreach ($lines as $index => $line) {
            if (\str_contains($line, $source)) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * How many refusals this flag would clear.
     *
     * @param list<array{lifts: string}> $refused
     */
    private static function lifted(array $refused, string $flag): int
    {
        $count = 0;
        foreach ($refused as $refusal) {
            if ($flag === $refusal['lifts']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * What `--fix` would do, given what the run found.
     */
    private static function fixes(int $shipped, int $urls): string
    {
        $parts = [];
        if ($shipped > 0) {
            $parts[] = 1 === $shipped
                ? 'drops the shipped entry from composer.json'
                : 'drops the '.$shipped.' shipped entries from composer.json';
        }
        if ($urls > 0) {
            $parts[] = 1 === $urls
                ? 'adopts the patch declared as a URL'
                : 'adopts the '.$urls.' patches declared as URLs';
        }

        return \implode(' and ', $parts);
    }

    /**
     * The patch file as a reader would name it: the last segment of its path or URL, without a query string.
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

        return self::fit(false === $cut ? $path : \substr($path, $cut + 1), self::TRAILING_MAX);
    }

    /**
     * One warning, marked so it reads as a caveat on the rows near it.
     */
    private static function warning(string $warning): string
    {
        return '  <comment>! '.$warning.'</comment>';
    }

    /**
     * Warnings grouped under the package each one is about; a warning opens with the package it names.
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
     * The warnings that name no package at all; those lead the report.
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
        \uksort($counts, static fn (string $a, string $b): int => [self::rank($a), $a] <=> [self::rank($b), $b]);

        return self::tally($counts);
    }

    /**
     * The package line: the release the verdicts are about, and the one the lock holds when they differ.
     */
    private static function heading(PatchRow $row): string
    {
        // Nothing was judged: the heading names the release the lock
        // holds and the rows say why they carry no verdict.
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

    /**
     * The packages carrying at least one row of a verdict, in plan order and named once each.
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
