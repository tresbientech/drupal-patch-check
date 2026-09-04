<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\PatchText;
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

    /** Row indent, the number column and its space, the mark and its space, the verdict column and its space. */
    public const PREFIX = 20;

    /** The number column, right-aligned: room for #99. */
    private const NUMBER_WIDTH = 3;

    /** Row indent, the number column and its space: where a package-level caveat starts, under the mark. */
    private const MARK_INDENT = '        ';

    /** The longest filename column, so the narrowest row still fits. */
    public const TRAILING_MAX = 32;

    /** Between the title and the filename. */
    private const GAP = 2;

    /** Below this a title says nothing, so the row runs long instead. */
    private const MIN_TITLE = 24;

    /** What a shortened string ends with. */
    private const ELLIPSIS = '…';

    /** What the report is introduced by, and what the update hook calls itself. */
    public const LABEL = 'Drupal Patch Check';

    /** The header a run that judged nothing carries instead of a count. */
    private const NOTHING_CHECKED = self::LABEL.': no patch could be checked; the reasons are below';

    /** What the footer is introduced by. */
    private const NEXT = 'Next:';

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
     * The whole report in printed order: the rows, the files a re-roll wrote, what it would not write, what a fix rewrote, then what to run next.
     *
     * @param list<string> $scope the options a next run repeats, `--target 11.4.5` and each `--package`
     *
     * @return list<string>
     */
    public static function report(Plan $plan, Coverage $coverage, ?Outcomes $outcomes, int $width = 100, array $scope = []): array
    {
        return \array_merge(
            self::lines($plan, $coverage, $width, $outcomes),
            self::written($outcomes),
            self::refused($outcomes),
            self::rewrite($outcomes),
            self::upstream($plan),
            self::footer($plan, $outcomes, $scope),
        );
    }

    /**
     * The table a person reads: what the run held back, then patches grouped under their package with the release each verdict is about, and the tallies underneath.
     *
     * @param Outcomes|null $outcomes set for a run that wrote, so an applies row with nothing under it is left out
     *
     * @return list<string>
     */
    public static function lines(Plan $plan, Coverage $coverage, int $width = 100, ?Outcomes $outcomes = null): array
    {
        $trailing = 0;
        foreach ($plan->patches as $patch) {
            $trailing = \max($trailing, \mb_strlen(self::fileName($patch)));
        }
        $titleWidth = self::title($width, $trailing);
        $total = \count($plan->patches);
        $lines = [$coverage->isVacuous() ? self::caveat(self::NOTHING_CHECKED) : \sprintf(
            '<info>%s</info>: %d patch%s %s',
            self::LABEL,
            $total,
            1 === $total ? '' : 'es',
            $plan->scenario()
        ), ''];

        $grouped = [];
        foreach ($plan->patches as $row) {
            $grouped[$row->package][] = $row;
        }
        // The service states a blocked package on its scan row; a plan
        // warning names no package at all.
        $placed = $plan->rowNotes;

        $blocks = [];
        $loose = $plan->warnings;
        if ([] !== $loose) {
            $blocks[] = \array_map(self::warning(...), $loose);
        }
        $unjudged = $coverage->unjudged(\array_keys($grouped));
        if ([] !== $unjudged) {
            $blocks[] = \array_map(static fn (string $line): string => '  '.self::caveat($line), $unjudged);
        }
        // A run that writes is about the files it wrote. The table is the
        // plain run's answer, and repeating it here buries the footer.
        if (null === $outcomes) {
            foreach ($grouped as $package => $rows) {
                $note = $placed[$package] ?? '';
                $blocks[] = self::group($rows, '' === $note ? [] : [$note], $coverage->notesFor($package), $titleWidth);
            }
        }
        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $lines[] = '';
            }
            foreach ($block as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = '  patches: '.(null === $outcomes ? self::tally($plan->counts) : self::writeTally($plan, $outcomes));

        // A path the service says it never received that the run did not
        // hold back: the text was lost rather than kept back on purpose.
        $lost = \array_values(\array_diff($plan->missingFiles, $coverage->withheld()));
        if ([] !== $lost) {
            $lines[] = '  patch text not sent for: '.\implode(', ', $lost);
        }

        return $lines;
    }

    /**
     * One package's block: the heading, what keeps its release back, the rows numbered in the order composer applies them, and what the run skipped.
     *
     * @param non-empty-list<PatchRow> $rows
     * @param list<string>             $warnings
     * @param list<string>             $notes
     *
     * @return list<string>
     */
    private static function group(array $rows, array $warnings, array $notes, int $titleWidth): array
    {
        $lines = ['  '.self::heading($rows[0]).'   '.self::packageTally($rows)];
        foreach ($warnings as $warning) {
            $lines[] = self::MARK_INDENT.self::caveat('! '.$warning);
        }
        $numbers = [];
        foreach ($rows as $i => $row) {
            $numbers[$row->label()] = $i + 1;
        }
        foreach ($rows as $i => $row) {
            $details = self::details($row, $numbers);
            $lines[] = \rtrim(\sprintf(
                '    %'.self::NUMBER_WIDTH.'s %s %-9s %s  %s',
                '#'.($i + 1),
                self::marked($row->verdict),
                $row->verdict,
                self::pad(self::fit($row->label(), $titleWidth), $titleWidth),
                self::fileName($row),
            ));
            foreach ($details as $line) {
                $lines[] = self::detailIndent().self::detail($line);
            }
        }
        foreach ($notes as $note) {
            $lines[] = self::MARK_INDENT.self::caveat($note);
        }

        return $lines;
    }

    /**
     * What is printed under a row, in order: why it has no verdict, what a re-roll is up against, the hunks already in the release, the file the merge ran on, the regions the merge decided, a strict apply that refused, the earlier patch it was judged without, and the core symbols the target changed.
     *
     * @param array<string, int> $numbers the package's patches by label, in the order composer applies them
     *
     * @return list<string>
     */
    private static function details(PatchRow $row, array $numbers): array
    {
        $out = [];
        if ('' !== $row->reason()) {
            $out[] = $row->reason();
        }
        $shipped = \array_flip($row->hunksShipped);
        foreach ($row->failures() as $place => $failure) {
            // A hunk the release already carries is why the patch stopped
            // applying there, so the two lines about it become one.
            $out[] = isset($shipped[$place])
                ? $place.': already in the release, not needed'
                : $failure;
        }
        $failed = $row->failures();
        // Server JSON is the boundary: a total below what it sent prints
        // nothing rather than a negative count.
        $more = $row->failedTotal - \count($failed);
        if ($more > 0) {
            $out[] = '+'.$more.' more failed hunk'.(1 === $more ? '' : 's');
        }
        foreach ($row->hunksShipped as $place) {
            if (!isset($failed[$place])) {
                $out[] = 'already in the release: '.$place;
            }
        }
        $more = $row->shippedTotal - \count($row->hunksShipped);
        if ($more > 0) {
            $out[] = '+'.$more.' more hunk'.(1 === $more ? '' : 's').' already in the release';
        }
        // A plain run does not ask for a re-roll, and a re-roll is what
        // finds a patch the release carries whole. Say so where part of
        // one is already there.
        if ($row->conflicts() && [] !== $row->hunksShipped) {
            $out[] = 'run --write to see if the release has the rest';
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
        if ([] !== $row->judgedWithout) {
            $out[] = self::judgedWithoutNote($row->judgedWithout, $numbers);
        }

        return \array_merge($out, self::coreReferenceLines($row));
    }

    /**
     * The earlier patches a row was judged behind, as it cites them.
     *
     * @param list<string>       $labels
     * @param array<string, int> $numbers
     */
    private static function judgedWithoutNote(array $labels, array $numbers): string
    {
        $cited = \array_map(static fn (string $label): string => self::cited($label, $numbers), $labels);
        if (1 === \count($cited)) {
            return 'judged with only the part of '.$cited[0].' that applied';
        }
        $last = \array_pop($cited);

        return 'judged with only the parts of '.\implode(', ', $cited).' and '.$last.' that applied';
    }

    /**
     * An earlier patch as a row cites it: its number in the package.
     *
     * @param array<string, int> $numbers
     */
    private static function cited(string $label, array $numbers): string
    {
        // Server JSON is the boundary: a label no row carries is printed
        // as it came.
        return isset($numbers[$label]) ? '#'.$numbers[$label] : '"'.$label.'"';
    }

    /**
     * The files a re-roll wrote, in two groups: the patches the site can
     * use, and the conflict files a person still has to decide.
     *
     * @return list<string>
     */
    public static function written(?Outcomes $outcomes): array
    {
        $clean = [];
        $conflicted = [];
        foreach (null === $outcomes ? [] : $outcomes->written() as $file) {
            if (PatchRow::CONFLICTS === $file['status']) {
                $conflicted[] = $file;
                continue;
            }
            $clean[] = $file;
        }

        return \array_merge(
            self::writtenFiles('re-rolled:', $clean),
            self::writtenFiles('re-rolled with conflicts:', $conflicted),
        );
    }

    /**
     * One group of written files under its heading.
     *
     * @param list<array{path: string, status: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $files
     *
     * @return list<string>
     */
    private static function writtenFiles(string $heading, array $files): array
    {
        if ([] === $files) {
            return [];
        }
        $lines = ['', '  '.$heading];
        foreach ($files as $file) {
            $lines[] = \sprintf('    %s  (%s)', $file['path'], self::status($file));
            if ([] !== $file['unioned']) {
                $lines[] = '      '.self::unionNote(\count($file['unioned'])).':';
                foreach ($file['unioned'] as $region) {
                    $lines[] = '        '.$region['file'].':'.$region['line'];
                }
            }
        }

        return $lines;
    }

    /**
     * A written file's status: usable and whether the server verified it, or how many regions a person has to decide.
     *
     * @param array{status: string, verified: bool, regions: int} $file
     */
    private static function status(array $file): string
    {
        if (PatchRow::CONFLICTS === $file['status']) {
            return \sprintf('%d region%s to decide', $file['regions'], 1 === $file['regions'] ? '' : 's');
        }

        // An unverified merge produced no conflict and nothing applied it,
        // so it is not yet a patch that works.
        return $file['verified'] ? 'verified against the release' : 'not verified';
    }

    /**
     * What a re-roll run would not write, grouped by reason.
     *
     * @return list<string>
     */
    public static function refused(?Outcomes $outcomes): array
    {
        $refused = null === $outcomes ? [] : $outcomes->refused();
        if ([] === $refused) {
            return [];
        }
        $groups = [];
        foreach ($refused as $refusal) {
            $groups[$refusal['reason']][] = $refusal;
        }
        \ksort($groups);
        $lines = ['', '  not re-rolled:'];
        // The reason heads its group. Printed after the patches it
        // explains, it reads as though the ones above it have none.
        foreach ($groups as $reason => $items) {
            \usort($items, static fn (array $a, array $b): int => [$a['package'], $a['title']] <=> [$b['package'], $b['title']]);
            $lines[] = '    '.$reason;
            foreach ($items as $item) {
                $lines[] = \sprintf('      %s  %s: %s', $item['path'], $item['package'], $item['title']);
            }
        }

        return $lines;
    }

    /**
     * What a fix rewrote, under the file it rewrote; a fix that found nothing says so.
     *
     * @return list<string>
     */
    public static function rewrite(?Outcomes $outcomes): array
    {
        if (null === $outcomes || !$outcomes->fixed()) {
            return [];
        }
        $changes = $outcomes->changes();
        if ([] === $changes) {
            return ['', \sprintf('  nothing to change in %s: no patch to remove, and every re-roll landed where its entry points', $outcomes->declaration())];
        }
        $lines = ['', '  '.$outcomes->declaration().':'];
        foreach ($changes as $change) {
            $lines[] = self::change($change);
        }

        return $lines;
    }

    /**
     * @param array{action: 'dropped'|'repointed', package: string, title: string, path: string} $change
     */
    private static function change(array $change): string
    {
        if ('repointed' === $change['action']) {
            return \sprintf('    ~ %s: %s → %s', $change['package'], $change['title'], $change['path']);
        }
        if ('' === $change['path']) {
            return \sprintf('    - %s: %s (already in the release)', $change['package'], $change['title']);
        }

        return \sprintf('    - %s: %s (already in the release; %s is no longer used and was kept)', $change['package'], $change['title'], $change['path']);
    }

    /**
     * The patch the merge ran on, named short.
     */
    public static function mergedFromNote(string $url): string
    {
        $tail = \substr($url, false === \strrpos($url, '/-/') ? 0 : \strrpos($url, '/-/') + 3);

        return 're-rolled from '.('' === $tail ? $url : $tail).', the merge request\'s own diff; the declared file decided the verdict';
    }

    /**
     * What the merge decided on its own, in one line.
     */
    public static function unionNote(int $regions): string
    {
        return \sprintf(
            'the release and the patch both added lines in %d region%s; the merge kept both additions, check them',
            $regions,
            1 === $regions ? '' : 's'
        );
    }

    /**
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
     * Where the re-roll of a conflicting patch belongs when the site took that patch from a merge request.
     *
     * @return list<string>
     */
    public static function upstream(Plan $plan): array
    {
        $requests = [];
        foreach ($plan->patches as $row) {
            $request = PatchText::mergeRequest($row->source);
            if ($row->conflicts() && '' !== $request) {
                $requests[$request] = $row->package;
            }
        }
        if ([] === $requests) {
            return [];
        }
        $lines = [''];
        foreach ($requests as $request => $package) {
            $lines[] = '  '.$package.' takes this patch from a merge request. Send the re-roll there and';
            $lines[] = '  every site using it is fixed: '.$request;
        }

        return $lines;
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
                .(0 === $i ? self::NEXT.'  ' : \str_repeat(' ', \strlen(self::NEXT) + 2))
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
            // One line per annotation, so the first failure stands for the rest.
            $failures = $row->failures();
            $detail = \reset($failures) ?: $row->reason();
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
        return '  '.self::caveat('! '.$warning);
    }

    /**
     * A line under a row, in the colour that sets it apart from the row.
     */
    private static function detail(string $text): string
    {
        return '<fg=cyan>'.$text.'</>';
    }

    /**
     * A line the colour alone marks as a caveat.
     */
    private static function caveat(string $text): string
    {
        return '<comment>'.$text.'</comment>';
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
     * What a write run changed and what it left, in the terms of the work
     * still to do rather than a second tally to compare against the first.
     */
    private static function writeTally(Plan $plan, Outcomes $outcomes): string
    {
        $fixed = [];
        foreach ($outcomes->written() as $file) {
            if (PatchRow::CONFLICTS !== $file['status'] && $file['verified']) {
                $fixed[PatchRow::keyOf($file['package'], $file['title'])] = true;
            }
        }
        $counts = [];
        foreach ($plan->patches as $row) {
            $verdict = isset($fixed[$row->key()]) ? PatchRow::APPLIES : $row->verdict;
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
        }
        $parts = [];
        if ([] !== $fixed) {
            $parts[] = \count($fixed).' now appl'.(1 === \count($fixed) ? 'ies' : 'y');
        }
        foreach ([PatchRow::CONFLICTS => ' conflicts left', PatchRow::UNKNOWN => ' unknown'] as $verdict => $what) {
            if (($n = $counts[$verdict] ?? 0) > 0) {
                $parts[] = $n.$what;
            }
        }
        // A fix run has already dropped the entries it could, so what is
        // left to drop is what it did not touch.
        $dropped = 0;
        foreach ($outcomes->changes() as $change) {
            if ('dropped' === $change['action']) {
                ++$dropped;
            }
        }
        if (($drop = ($counts[PatchRow::MERGED] ?? 0) - $dropped) > 0) {
            $parts[] = $drop.' to drop';
        }

        // A run that changed nothing and left nothing to do still owes the
        // reader a count.
        return [] === $parts ? self::tally($counts) : \implode(', ', $parts);
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
