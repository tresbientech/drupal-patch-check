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

    /** Flagged core references printed under a row before the rest is counted. */
    private const CORE_LINES = 3;

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
     * The whole report in printed order: the rows with what the run did to each, the conflict files left, a fix that changed nothing, then what to run next.
     *
     * @param list<string> $scope the options a next run repeats, `--target 11.4.5` and each `--package`
     *
     * @return list<string>
     */
    public static function report(Plan $plan, ?Outcomes $outcomes, int $width = 100, array $scope = []): array
    {
        return \array_merge(
            self::lines($plan, $width, $outcomes),
            self::open($outcomes),
            self::rewrite($outcomes),
            self::footer($plan, $outcomes, $scope),
        );
    }

    /**
     * The table a person reads: patches grouped under their package, the release each verdict is about, and the tallies underneath.
     *
     * @param Outcomes|null $outcomes what the run did to each patch; set, an applies row with nothing under it is left out
     *
     * @return list<string>
     */
    public static function lines(Plan $plan, int $width = 100, ?Outcomes $outcomes = null): array
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
                $details = \array_merge(self::details($row), null === $outcomes ? [] : $outcomes->under($row));
                if (null !== $outcomes && PatchRow::APPLIES === $row->verdict && [] === $details) {
                    continue;
                }
                $lines[] = \rtrim(\sprintf(
                    '    %s %-9s %s  %s',
                    self::marked($row->verdict),
                    $row->verdict,
                    self::pad(self::fit($row->label(), $titleWidth), $titleWidth),
                    self::fileName($row),
                ));
                foreach ($details as $line) {
                    $lines[] = $detail.$line;
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
     * What is printed under a row, in order: why it has no verdict, what a re-roll is up against, the hunks already in the release, the file the merge ran on, the regions the merge decided, a strict apply that refused, the earlier patch it was judged without, and the core symbols the target changed.
     *
     * @return list<string>
     */
    private static function details(PatchRow $row): array
    {
        $out = [];
        if ('' !== $row->reason()) {
            $out[] = $row->reason();
        }
        if ('' !== $row->firstFailure()) {
            $out[] = $row->firstFailure();
        }
        foreach ($row->hunksShipped as $shipped) {
            $out[] = 'already in the release: '.$shipped;
        }
        if ('' !== $row->mergedFrom()) {
            $out[] = self::mergedFromNote($row->mergedFrom());
        }
        if ([] !== $row->unioned()) {
            $out[] = self::unionNote(\count($row->unioned()));
        }
        if ('' !== $row->strictRefused) {
            $out[] = $row->strictRefused;
        }
        if ('' !== $row->judgedWithout) {
            $out[] = 'judged without "'.$row->judgedWithout.'", which did not apply';
        }

        return \array_merge($out, self::coreReferenceLines($row));
    }

    /**
     * The conflict files a run left, none of them usable as a patch.
     *
     * @return list<string>
     */
    public static function open(?Outcomes $outcomes): array
    {
        $count = null === $outcomes ? 0 : $outcomes->openConflictFiles();
        if (0 === $count) {
            return [];
        }

        return ['', \sprintf(
            '  %d re-roll%s left regions to decide; those files are not usable as patches',
            $count,
            1 === $count ? '' : 's'
        )];
    }

    /**
     * A fix that found nothing to rewrite says so; one that changed entries said so under their rows.
     *
     * @return list<string>
     */
    public static function rewrite(?Outcomes $outcomes): array
    {
        if (null === $outcomes || !$outcomes->fixed() || $outcomes->changed()) {
            return [];
        }

        return ['', '  nothing to change: no patch is already in the release and none was re-rolled cleanly'];
    }

    /**
     * The patch the merge ran on, named short.
     */
    public static function mergedFromNote(string $url): string
    {
        $tail = \substr($url, false === \strrpos($url, '/-/') ? 0 : \strrpos($url, '/-/') + 3);

        return 'merged from '.('' === $tail ? $url : $tail).', the squashed form of the patch declared';
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
     * /**
     * The core references under a row: one line per flagged finding up to the cap, the count left over, the deprecated count, then the server's note unless the row conflicts.
     *
     * @return list<string>
     */
    public static function coreReferenceLines(PatchRow $row): array
    {
        $block = $row->coreReferences;
        $flagged = \array_values((array) ($block['flagged'] ?? []));
        $out = [];
        foreach (\array_slice($flagged, 0, self::CORE_LINES) as $finding) {
            $finding = (array) $finding;
            // Server JSON is the boundary: a finding without the sentence
            // still names its symbol.
            $what = (string) ($finding['issue'] ?? '');
            if ('' === $what) {
                $what = (string) ($finding['symbol'] ?? '');
            }
            $record = (int) ($finding['change_record'] ?? 0);
            $out[] = 'core '.(string) ($finding['kind'] ?? '').': '.$what.($record > 0 ? ' (change record '.$record.')' : '');
        }
        $more = $row->flaggedCoreReferences() - \min(\count($flagged), self::CORE_LINES);
        if ($more > 0) {
            $out[] = '+'.$more.' more core reference'.(1 === $more ? '' : 's');
        }
        $deprecated = \count((array) ($block['deprecated'] ?? []));
        if ($deprecated > 0) {
            $out[] = 'core deprecated: '.$deprecated.' reference'.(1 === $deprecated ? '' : 's').', still present at '.(string) ($block['target'] ?? '');
        }
        // A conflicts row already says the patch does not apply, so the
        // note that the references went unchecked for that reason is not
        // repeated under it.
        $note = (string) ($block['note'] ?? '');
        if ('' !== $note && !$row->conflicts()) {
            $out[] = $note;
        }

        return $out;
    }

    /**
     * What to run next, empty when there is nothing to run.
     *
     * @param list<string> $scope
     *
     * @return list<string>
     */
    public static function footer(Plan $plan, ?Outcomes $outcomes = null, array $scope = []): array
    {
        $lines = self::nextStepLines($plan->counts, '  ', $outcomes, $scope);

        return [] === $lines ? [] : \array_merge([''], $lines);
    }

    /**
     * The commands worth running, worst finding first.
     *
     * @param array<string, int> $counts   patches per verdict
     * @param Outcomes|null      $outcomes what the run did, null for a run that wrote nothing
     *
     * @return list<array{flag: string, effect: string}>
     */
    public static function nextSteps(array $counts, ?Outcomes $outcomes = null): array
    {
        $out = [];
        if (null === $outcomes) {
            $reroll = $counts[PatchRow::CONFLICTS] ?? 0;
            if ($reroll > 0) {
                $out[] = [
                    'flag' => '--write',
                    'effect' => 1 === $reroll ? 'writes the re-roll' : 'writes the '.$reroll.' re-rolls',
                ];
            }
        } else {
            $open = $outcomes->openConflictFiles();
            if ($open > 0) {
                $out[] = [
                    'flag' => '--resolve',
                    'effect' => 1 === $open
                        ? 'sends the regions you decide in the conflict file'
                        : 'sends the regions you decide in the '.$open.' conflict files',
                ];
            }
            $forcible = $outcomes->lifted('--force');
            if ($forcible > 0) {
                $out[] = [
                    'flag' => '--force',
                    'effect' => 1 === $forcible
                        ? 'replaces the file this run would not overwrite'
                        : 'replaces the '.$forcible.' files this run would not overwrite',
                ];
            }
        }
        // A fix run dropped what shipped and adopted the URLs; offering it
        // again would repeat what just happened.
        $fixed = null !== $outcomes && $outcomes->fixed();
        $urls = null === $outcomes ? 0 : $outcomes->lifted('--fix');
        $shipped = $counts[PatchRow::MERGED] ?? 0;
        if (!$fixed && ($shipped > 0 || $urls > 0)) {
            $out[] = ['flag' => '--fix', 'effect' => self::fixes($shipped, $urls)];
        }

        return $out;
    }

    /**
     * The next-steps footer as printed, empty when there is nothing to run.
     *
     * @param array<string, int> $counts patches per verdict
     * @param list<string>       $scope  the options a next run repeats, between the command and the flag
     *
     * @return list<string>
     */
    public static function nextStepLines(array $counts, string $indent = '  ', ?Outcomes $outcomes = null, array $scope = []): array
    {
        $steps = self::nextSteps($counts, $outcomes);
        if ([] === $steps) {
            return [];
        }
        $commands = [];
        foreach ($steps as $step) {
            $commands[] = \implode(' ', [self::COMMAND, ...$scope, $step['flag']]);
        }
        $widest = \max(\array_map(\strlen(...), $commands));
        $lines = [];
        foreach ($steps as $i => $step) {
            $lines[] = $indent
                .(0 === $i ? self::LABEL.'  ' : \str_repeat(' ', \strlen(self::LABEL) + 2))
                .self::pad($commands[$i], $widest)
                .'   '.$step['effect'];
        }

        return $lines;
    }

    /**
     * The `--json` summary a scheduled job reads: what the run was about, what it found, which packages are behind each finding, and what to run next.
     *
     * @return array<string, mixed>
     */
    public static function summary(Plan $plan, bool $strict = false, bool $vacuous = false, ?Outcomes $outcomes = null): array
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
        $next = self::nextSteps($counts, $outcomes);
        if ([] !== $next) {
            $summary['next'] = $next;
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
            $message = $row->verdict.' '.$row->package.('' === $row->version ? '' : ' '.$row->version).': '.$row->label();
            // What a re-roll must fix, or why a row has no verdict.
            $detail = '' !== $row->firstFailure() ? $row->firstFailure() : $row->reason();
            if ('' !== $detail) {
                $message .= '; '.$detail;
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
