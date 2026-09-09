<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\MergeRequest;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\RerollCommand;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\PatchFiles;

/**
 * The command's output: the table a person reads and the JSON summary a job reads.
 *
 * @phpstan-import-type WrittenRow from \TresBienTech\Drupatch\Render\Outcomes
 */
class Report
{
    /** The command the hook and the hints point at for the report. */
    public const COMMAND = 'composer '.CheckCommand::NAME;

    /** The command every next-step suggestion runs. */
    public const REROLL = 'composer '.RerollCommand::NAME;

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

    /** Over a shipped patch whose declaration the site still carries. */
    private const SHIPPED_KEPT = 'already in the release, drop it:';

    /** Over a shipped patch whose declaration this run removed. */
    private const SHIPPED_DROPPED = 'already in the release, dropped:';

    /** What the footer is introduced by. */
    private const NEXT = 'Next:';

    /** Flagged core references printed under a row before the rest is counted. */
    private const CORE_LINES = 3;

    /** Broken lines printed under a row before the rest is counted. One lost brace cascades into every method below it. */
    private const SYNTAX_LINES = 3;

    /** Printed once by a plain run against the installed releases that still has a conflict: the verdict answers what the release has, the installed files do not. */
    private const ON_DISK = 'composer already applied these patches to your files';

    /** The command that copies a merge request patch into the site. */
    public const PIN = 'composer drupatch:pin';

    /** Under a row the site declared as a merge request URL. */
    public const UNPINNED_ROW = 'declared as a merge request URL';

    /** Under a row whose copy no longer holds what the site took. */
    public const EDITED_ROW = 'edited since it was copied into the site';

    /** The two lines after the count, wrapped for the narrowest terminal. */
    private const UNPINNED_REST = [
        'account can push to a merge request, so what composer applies here can',
        'change between two installs. Run: '.self::PIN,
    ];

    /**
     * Mark, colour tag and sort rank per row status, worst first. A status is the verdict, or the failure mode when the patch applied and left something broken. An unrecognised one gets the fallback and sorts with the work.
     *
     * @var array<string, array{string, string, int}>
     */
    private const STATUSES = [
        PatchRow::BROKEN_SYNTAX => ['!', 'error', 0],
        'conflicts' => ['!', 'error', 0],
        'unknown' => ['?', 'comment', 1],
        'applies' => ['·', '', 2],
        'merged' => ['✓', 'info', 3],
    ];

    /** @var array{string, string, int} */
    private const UNRECOGNISED = ['*', 'comment', 1];

    public static function mark(string $status): string
    {
        return (self::STATUSES[$status] ?? self::UNRECOGNISED)[0];
    }

    /**
     * The composer output tag the mark is written with, empty for none.
     */
    public static function tag(string $status): string
    {
        return (self::STATUSES[$status] ?? self::UNRECOGNISED)[1];
    }

    /**
     * Where the status sorts; lower comes first, so the work is at the top.
     */
    public static function rank(string $status): int
    {
        return (self::STATUSES[$status] ?? self::UNRECOGNISED)[2];
    }

    /**
     * The mark ready to print, wrapped in its colour when it has one.
     */
    public static function marked(string $status): string
    {
        [$mark, $tag] = self::STATUSES[$status] ?? self::UNRECOGNISED;

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
            self::shipped($outcomes),
            self::written($outcomes),
            self::refused($outcomes),
            self::rewrite($outcomes),
            self::footer($plan, $outcomes, $scope),
        );
    }

    /**
     * The table a person reads: what the run held back, then patches grouped under their package with the release each verdict is about, and the tallies underneath.
     *
     * @param Outcomes|null $outcomes set for a run that wrote, which prints neither the rows nor the skipped packages
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
        $headline = '<info>'.self::LABEL.'</info>: '.Text::plural(
            $total,
            '@count patch @scenario',
            '@count patches @scenario',
            ['@scenario' => $plan->scenario()]
        );
        $lines = [$coverage->isVacuous() ? self::caveat(self::NOTHING_CHECKED) : $headline, ''];

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
        // The skipped packages are the plain run's to list. A run that
        // writes did nothing about them, unless they are all it has to say.
        $unjudged = null === $outcomes || $coverage->isVacuous() ? $coverage->unjudged(\array_keys($grouped)) : [];
        if ([] !== $unjudged) {
            $blocks[] = \array_map(static fn (string $line): string => '  '.self::caveat($line), $unjudged);
        }
        // A run that writes is about the files it wrote. The table is the
        // plain run's answer, and repeating it here buries the footer.
        if (null === $outcomes) {
            foreach ($grouped as $package => $rows) {
                $note = $placed[$package] ?? '';
                $blocks[] = self::group($rows, '' === $note ? [] : [$note], $coverage->notesFor($package), $titleWidth, $coverage->edited());
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

        if ([] !== $blocks) {
            $lines[] = '';
        }
        $lines[] = '  '.Text::t('patches: @tally', ['@tally' => null === $outcomes ? self::tally(self::headlineCounts($plan)) : self::writeTally($plan, $outcomes)]);
        // The caveat is about the table's verdicts, which only a plain run
        // prints. A write run has just put re-rolls on disk that composer
        // has applied nothing of, so saying this there reads as if it had.
        // A target run judges a release the site does not install, and
        // composer applied nothing against that one.
        if (null === $outcomes && $plan->targetIsInstalled && ($plan->counts[PatchRow::CONFLICTS] ?? 0) > 0) {
            $lines[] = self::caveat('  '.self::ON_DISK);
        }

        // A path the service says it never received that the run did not
        // hold back: the text was lost rather than kept back on purpose.
        $lost = \array_values(\array_diff($plan->missingFiles, $coverage->withheld()));
        if ([] !== $lost) {
            $lines[] = '  '.Text::t('patch text not sent for: @sources', ['@sources' => \implode(', ', $lost)]);
        }
        foreach (self::unpinnedWarning(\count(self::unpinned($plan))) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The rows whose declared source is a merge request, whose bytes anyone with a drupal.org account can change.
     *
     * @return list<PatchRow>
     */
    public static function unpinned(Plan $plan): array
    {
        $out = [];
        foreach ($plan->patches as $row) {
            if (null !== MergeRequest::of($row->source)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * How many patches the site downloads from a merge request, as a sentence.
     */
    public static function unpinnedCount(int $count): string
    {
        return Text::plural(
            $count,
            '@count patch is declared as a merge request URL.',
            '@count patches are declared as merge request URLs.'
        );
    }

    /**
     * The warning under the tally: how many, who can change them, and the command.
     *
     * @return list<string>
     */
    public static function unpinnedWarning(int $count): array
    {
        if (0 === $count) {
            return [];
        }
        $lines = ['', '  <fg=red>'.Text::t('@sentence Anyone with a drupal.org', ['@sentence' => self::unpinnedCount($count)]).'</>'];
        foreach (self::UNPINNED_REST as $line) {
            $lines[] = '  <fg=red>'.$line.'</>';
        }

        return $lines;
    }

    /**
     * One package's block: the heading, what keeps its release back, the rows numbered in the order composer applies them, and what the run skipped.
     *
     * @param non-empty-list<PatchRow> $rows
     * @param list<string>             $warnings
     * @param list<string>             $notes
     * @param list<string>             $edited   sources whose copy no longer holds what the site took
     *
     * @return list<string>
     */
    private static function group(array $rows, array $warnings, array $notes, int $titleWidth, array $edited = []): array
    {
        $lines = ['  '.Text::t('@heading   @tally', ['@heading' => self::heading($rows[0]), '@tally' => self::packageTally($rows)])];
        foreach ($warnings as $warning) {
            $lines[] = self::MARK_INDENT.self::caveat(Text::t('! @warning', ['@warning' => $warning]));
        }
        foreach ($rows as $i => $row) {
            $details = self::details($row);
            $lines[] = \rtrim(\sprintf(
                '    %'.self::NUMBER_WIDTH.'s %s %-9s %s  %s',
                '#'.($i + 1),
                self::marked($row->status()),
                $row->verdict,
                self::pad(self::fit($row->label(), $titleWidth), $titleWidth),
                self::fileName($row),
            ));
            if (null !== MergeRequest::of($row->source)) {
                $lines[] = self::detailIndent().'<fg=red>'.self::UNPINNED_ROW.'</>';
            }
            if (\in_array($row->source, $edited, true)) {
                $lines[] = self::detailIndent().'<fg=red>'.self::EDITED_ROW.'</>';
            }
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
     * @return list<string>
     */
    private static function details(PatchRow $row): array
    {
        $out = [];
        if ('' !== $row->reason()) {
            $out[] = $row->reason();
        }
        foreach (\array_slice($row->syntaxErrors, 0, self::SYNTAX_LINES) as $error) {
            $out[] = Text::t('@mode: @error', ['@mode' => $row->failureMode, '@error' => $error]);
        }
        $more = \count($row->syntaxErrors) - self::SYNTAX_LINES;
        if ($more > 0) {
            $out[] = Text::plural($more, '+@count more broken line', '+@count more broken lines');
        }
        $shipped = \array_flip($row->hunksShipped);
        foreach ($row->failures() as $place => $failure) {
            // A hunk the release already carries is why the patch stopped
            // applying there, so the two lines about it become one.
            $out[] = isset($shipped[$place])
                ? Text::t('@place: already in the release, not needed', ['@place' => $place])
                : $failure;
        }
        $failed = $row->failures();
        // The count says what the list left out, and only a conflicting
        // row lists hunks. Server JSON is the boundary: a total below
        // what it sent prints nothing rather than a negative count.
        $more = $row->failedTotal - \count($failed);
        if ($row->conflicts() && $more > 0) {
            $out[] = Text::plural($more, '+@count more failed hunk', '+@count more failed hunks');
        }
        foreach ($row->hunksShipped as $place) {
            if (!isset($failed[$place])) {
                $out[] = Text::t('already in the release: @place', ['@place' => $place]);
            }
        }
        $more = $row->shippedTotal - \count($row->hunksShipped);
        if ($more > 0) {
            $out[] = Text::plural($more, '+@count more hunk already in the release', '+@count more hunks already in the release');
        }
        // A plain run does not ask for a re-roll, and a re-roll is what
        // finds a patch the release carries whole. Say so where part of
        // one is already there.
        if ($row->conflicts() && [] !== $row->hunksShipped) {
            $out[] = Text::t('run @command to see if the release has the rest', ['@command' => self::REROLL]);
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
            $out[] = self::judgedWithoutNote($row->judgedWithout);
        }

        return \array_merge($out, self::coreReferenceLines($row));
    }

    /**
     * The earlier patches a row was judged behind, as it cites them.
     *
     * @param list<string> $labels
     */
    private static function judgedWithoutNote(array $labels): string
    {
        $cited = \array_map(self::cited(...), $labels);
        $last = (string) \array_pop($cited);
        $patches = [] === $cited ? $last : Text::t('@earlier and @last', ['@earlier' => \implode(', ', $cited), '@last' => $last]);

        return Text::t('judged after @patches applied in part', ['@patches' => $patches]);
    }

    /**
     * An earlier patch as a row cites it: the place in the package the service names, which is the number the rows are printed with.
     */
    private static function cited(string $label): string
    {
        // Server JSON is the boundary: a name that is not a row number is
        // printed as it came.
        return 1 === \preg_match('/^#\d+$/', $label) ? $label : Text::t('"@label"', ['@label' => $label]);
    }

    /**
     * The patches the release already carries, under the heading that says whether the run dropped the declaration: what is left to remove, then what it removed. Each entry names the source the site declared.
     *
     * @return list<string>
     */
    public static function shipped(?Outcomes $outcomes): array
    {
        if (null === $outcomes) {
            return [];
        }
        $dropped = [];
        foreach ($outcomes->changes() as $change) {
            if ('dropped' === $change['action']) {
                $dropped[PatchRow::keyOf($change['package'], $change['title'])] = true;
            }
        }
        $groups = [self::SHIPPED_KEPT => [], self::SHIPPED_DROPPED => []];
        foreach ($outcomes->refused() as $refusal) {
            if (!$refusal['shipped']) {
                continue;
            }
            $heading = isset($dropped[PatchRow::keyOf($refusal['package'], $refusal['title'])]) ? self::SHIPPED_DROPPED : self::SHIPPED_KEPT;
            $groups[$heading][] = $refusal;
        }
        $lines = [];
        foreach ($groups as $heading => $items) {
            if ([] === $items) {
                continue;
            }
            $lines[] = '';
            $lines[] = '  '.$heading;
            foreach ($items as $item) {
                $lines[] = '    '.Text::t('@package: @title', ['@package' => $item['package'], '@title' => $item['title']]);
                $lines[] = '      '.$item['path'];
            }
        }

        return $lines;
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
     * @param list<array{path: string, status: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int, open: list<array{file: string, region: int}>, removed: list<string>, from: string}> $files
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
            $lines[] = '    '.Text::t('@path  (@status)', ['@path' => $file['path'], '@status' => self::status($file)]);
            // A decision names its region by file and index, so the two
            // are printed for every region the run left open.
            foreach ($file['open'] as $region) {
                $lines[] = '      '.Text::t('@file region @region', ['@file' => $region['file'], '@region' => $region['region']]);
            }
            // With regions of its own to show, the status line names the
            // count, so a removed file needs its own line here.
            if ($file['regions'] > 0) {
                foreach ($file['removed'] as $gone) {
                    $lines[] = '      '.Text::t('the release removed @file', ['@file' => $gone]);
                }
            }
            // The site did not have this file before the run put it there.
            if ('' !== $file['from']) {
                $lines[] = '      '.Text::t('copied into the site from @source', ['@source' => $file['from']]);
                // A patch taken from a merge request is shared work, so the
                // re-roll belongs where the people who share it will get it.
                if (null !== MergeRequest::of($file['from'])) {
                    $lines[] = '      send your re-roll to that merge request and every site using it is fixed';
                }
            }
            if ([] !== $file['unioned']) {
                $lines[] = '      '.Text::t('@note:', ['@note' => self::unionNote(\count($file['unioned']))]);
                foreach ($file['unioned'] as $region) {
                    $lines[] = '        '.Text::t('@file:@line', ['@file' => $region['file'], '@line' => $region['line']]);
                }
            }
        }

        return $lines;
    }

    /**
     * A written file's status: usable and whether the server verified it, or how many regions a person has to decide.
     *
     * @param array{status: string, verified: bool, regions: int, removed: list<string>} $file
     */
    private static function status(array $file): string
    {
        if (PatchRow::CONFLICTS === $file['status']) {
            // A file the release removed leaves no region anyone can
            // decide, so the file itself is the answer.
            if (0 === $file['regions'] && [] !== $file['removed']) {
                return Text::t('the release removed @files', ['@files' => \implode(', ', $file['removed'])]);
            }

            return Text::plural($file['regions'], '@count region to decide', '@count regions to decide');
        }

        // An unverified merge produced no conflict and nothing applied it,
        // so it is not yet a patch that works.
        return $file['verified'] ? 'verified against the release' : 'not verified';
    }

    /**
     * What a re-roll run would not write, grouped by reason in the order the site declares the patches; a patch the release already carries is not among them.
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
            // A shipped patch has its own block above; here is the work.
            if ($refusal['shipped']) {
                continue;
            }
            $groups[$refusal['reason']][] = $refusal;
        }
        if ([] === $groups) {
            return [];
        }
        $lines = ['', '  not re-rolled:'];
        // The reason heads its group, placed where its first patch falls
        // in the site's own order. Printed after the patches it explains,
        // it reads as though the ones above it have none.
        foreach ($groups as $reason => $items) {
            $lines[] = '    '.$reason;
            foreach ($items as $item) {
                $lines[] = '      '.Text::t('@path  @package: @title', ['@path' => $item['path'], '@package' => $item['package'], '@title' => $item['title']]);
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
            return ['', '  '.Text::t('@file is unchanged: nothing to delete, and every re-roll was saved over the old file', ['@file' => $outcomes->declaration()])];
        }
        $lines = ['', '  '.Text::t('@file:', ['@file' => $outcomes->declaration()])];
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
        $values = ['@package' => $change['package'], '@title' => $change['title'], '@path' => $change['path']];
        if ('repointed' === $change['action']) {
            return '    '.Text::t('~ @package: @title → @path', $values);
        }
        if ('' === $change['path']) {
            return '    '.Text::t('- @package: @title (already in the release)', $values);
        }

        return '    '.Text::t('- @package: @title (already in the release; @path is no longer used and was kept)', $values);
    }

    /**
     * The patch the merge ran on, named short.
     */
    public static function mergedFromNote(string $url): string
    {
        $tail = \substr($url, false === \strrpos($url, '/-/') ? 0 : \strrpos($url, '/-/') + 3);

        return Text::t('merged from @patch; the verdict used your declared file', ['@patch' => '' === $tail ? $url : $tail]);
    }

    /**
     * What the merge decided on its own, in one line.
     */
    public static function unionNote(int $regions): string
    {
        return Text::plural(
            $regions,
            'the merge kept both additions in @count region, check it',
            'the merge kept both additions in @count regions, check them'
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
            $out[] = Text::t(
                $record > 0 ? 'core @kind: @what (change record @record)' : 'core @kind: @what',
                ['@kind' => (string) ($finding['kind'] ?? ''), '@what' => $what, '@record' => $record]
            );
        }
        $more = $row->flaggedCoreReferences() - \min(\count($flagged), self::CORE_LINES);
        if ($more > 0) {
            $out[] = Text::plural($more, '+@count more core reference', '+@count more core references');
        }
        $deprecated = \count((array) ($block['deprecated'] ?? []));
        if ($deprecated > 0) {
            $out[] = Text::plural(
                $deprecated,
                'core deprecated: @count reference, still present at @target',
                'core deprecated: @count references, still present at @target',
                ['@target' => (string) ($block['target'] ?? '')]
            );
        }
        // A conflicts row already says the patch does not apply, and a
        // broken row already printed the parse errors this note repeats,
        // so neither takes it.
        $note = (string) ($block['note'] ?? '');
        if ('' !== $note && !$row->conflicts() && '' === $row->failureMode) {
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
     * @return list<array{command: string, flag: string, effect: string}>
     */
    public static function nextSteps(array $counts, ?Outcomes $outcomes = null): array
    {
        $out = [];
        $shipped = $counts[PatchRow::MERGED] ?? 0;
        if (null === $outcomes) {
            $reroll = $counts[PatchRow::CONFLICTS] ?? 0;
            if ($reroll > 0 || $shipped > 0) {
                $out[] = ['command' => self::REROLL, 'flag' => '', 'effect' => self::rerollEffect($reroll, $shipped)];
            }
        } else {
            $open = $outcomes->openConflictFiles();
            if ($open > 0) {
                $out[] = [
                    'command' => self::REROLL,
                    'flag' => '',
                    'effect' => Text::plural(
                        $open,
                        'sends the regions you decide in the conflict file',
                        'sends the regions you decide in the @count conflict files'
                    ),
                ];
            }
            $forcible = $outcomes->lifted('--force');
            if ($forcible > 0) {
                $out[] = [
                    'command' => self::REROLL,
                    'flag' => '--force',
                    'effect' => Text::plural(
                        $forcible,
                        'replaces the file this run would not overwrite',
                        'replaces the @count files this run would not overwrite'
                    ),
                ];
            }
            // A run that rewrote the declarations dropped what shipped, so
            // offering it again would repeat what just happened.
            if (!$outcomes->fixed() && $shipped > 0) {
                $out[] = ['command' => self::REROLL, 'flag' => '', 'effect' => self::rerollEffect(0, $shipped)];
            }
        }
        $urls = null === $outcomes ? 0 : $outcomes->refusedBecause(PatchFiles::URL_DECLARED);
        if ($urls > 0) {
            $out[] = [
                'command' => self::PIN,
                'flag' => '',
                'effect' => Text::plural(
                    $urls,
                    'copies the patch declared as a URL into the site',
                    'copies the @count patches declared as URLs into the site'
                ),
            ];
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
            $commands[] = \implode(' ', \array_filter([$step['command'], ...$scope, $step['flag']], static fn (string $part): bool => '' !== $part));
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
     * The `--format=json` summary a scheduled job reads: what the run was about, what it found, which packages are behind each finding, and what to run next.
     *
     * @return array<string, mixed>
     */
    public static function summary(Plan $plan, ?Outcomes $outcomes = null): array
    {
        $counts = [];
        foreach ($plan->patches as $row) {
            $counts[$row->status()] = ($counts[$row->status()] ?? 0) + 1;
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
            'broken' => self::packagesWith($plan, PatchRow::BROKEN_SYNTAX),
            'blocked' => $plan->noRelease,
            'decided_by' => $sources,
            'exit_code' => $plan->exitCode(),
        ];
        if ('' !== $plan->targetFrom) {
            $summary['target_from'] = $plan->targetFrom;
        }
        $unpinned = [];
        foreach (self::unpinned($plan) as $row) {
            $unpinned[] = ['package' => $row->package, 'title' => $row->title, 'source' => $row->source];
        }
        if ([] !== $unpinned) {
            $summary['unpinned'] = $unpinned;
        }
        $next = self::nextSteps($counts, $outcomes);
        if ([] !== $next) {
            $summary['next'] = $next;
        }

        return $summary;
    }

    /**
     * What a re-roll run would do, given what the run found.
     */
    private static function rerollEffect(int $conflicts, int $shipped): string
    {
        $parts = [];
        if ($conflicts > 0) {
            $parts[] = Text::plural($conflicts, 'writes the re-roll', 'writes the @count re-rolls');
        }
        if ($shipped > 0) {
            $parts[] = Text::plural(
                $shipped,
                'drops the shipped entry from composer.json',
                'drops the @count shipped entries from composer.json'
            );
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
            $counts[$row->status()] = ($counts[$row->status()] ?? 0) + 1;
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
            return Text::t('@package @release', ['@package' => $row->package, '@release' => $row->installed]);
        }
        if (!$row->movesRelease()) {
            return Text::t('@package @release', ['@package' => $row->package, '@release' => '' === $row->installed ? $row->version : $row->installed]);
        }

        return Text::t('@package @installed → @target', ['@package' => $row->package, '@installed' => $row->installed, '@target' => $row->version]);
    }

    /**
     * The patches a write turned into something that applies, keyed as a row is.
     *
     * @return array<string, true>
     */
    private static function fixedByWrite(Outcomes $outcomes): array
    {
        $fixed = [];
        foreach ($outcomes->written() as $file) {
            if (PatchRow::CONFLICTS !== $file['status'] && $file['verified']) {
                $fixed[PatchRow::keyOf($file['package'], $file['title'])] = true;
            }
        }

        return $fixed;
    }

    /**
     * What a write run changed and what it left, in the terms of the work still to do rather than a second tally to compare against the first.
     */
    private static function writeTally(Plan $plan, Outcomes $outcomes): string
    {
        $fixed = self::fixedByWrite($outcomes);
        $counts = [];
        foreach ($plan->patches as $row) {
            $status = isset($fixed[$row->key()]) ? PatchRow::APPLIES : $row->status();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $parts = [];
        if ([] !== $fixed) {
            $parts[] = Text::plural(\count($fixed), '@count now applies', '@count now apply');
        }
        foreach ([PatchRow::CONFLICTS => '@count conflicts left', PatchRow::UNKNOWN => '@count unknown'] as $verdict => $template) {
            if (($n = $counts[$verdict] ?? 0) > 0) {
                $parts[] = Text::t($template, ['@count' => $n]);
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
            $parts[] = Text::t('@count to drop', ['@count' => $drop]);
        }

        // A run that changed nothing and left nothing to do still owes the
        // reader a count.
        return [] === $parts ? self::tally($counts) : \implode(', ', $parts);
    }

    /**
     * The plan's counts with the broken patches taken out of their verdict and counted as what is wrong with them. The verdict they keep is the one the server sent.
     *
     * @return array<string, int>
     */
    private static function headlineCounts(Plan $plan): array
    {
        $counts = $plan->counts;
        foreach ($plan->patches as $row) {
            if ('' === $row->failureMode) {
                continue;
            }
            $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) - 1;
            $counts[$row->failureMode] = ($counts[$row->failureMode] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function tally(array $counts): string
    {
        $parts = [];
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $parts[] = Text::t('@count @verdict', ['@count' => $count, '@verdict' => $name]);
            }
        }

        return [] === $parts ? 'none' : \implode(', ', $parts);
    }

    /**
     * The packages carrying at least one row of a status, in plan order and named once each.
     *
     * @return list<string>
     */
    private static function packagesWith(Plan $plan, string $status): array
    {
        $seen = [];
        foreach ($plan->patches as $row) {
            if ($row->status() === $status) {
                $seen[$row->package] = true;
            }
        }

        return \array_keys($seen);
    }
}
