<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Render;

use Closure;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Command\PinCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\Declarations;
use TresBienTech\Drupatch\Write\PatchFiles;

/**
 * Every line the plugin prints, and the `--format=json` document a job reads.
 *
 * A report is an ordered list of sections. A section is a kind, a heading and
 * items. An item is a line, or a list of the lines one step under the line
 * before it. `print()` lays out every section of every report.
 *
 * @phpstan-import-type Coverage from \TresBienTech\Drupatch\Read\Run
 * @phpstan-import-type WrittenRow from PatchFiles
 * @phpstan-import-type Refused from PatchFiles
 * @phpstan-import-type Change from Declarations
 * @phpstan-import-type MoveReport from UpgradeCommand
 * @phpstan-import-type Refusal from UpgradeCommand
 * @phpstan-import-type Wrote from UpgradeCommand
 * @phpstan-import-type Outcome from \TresBienTech\Drupatch\Command\AddCommand
 * @phpstan-import-type Candidate from \TresBienTech\Drupatch\Source\Ranking
 * @phpstan-import-type Ran from ManagerCommands
 * @phpstan-import-type Hunk from PatchRow
 *
 * @phpstan-type Section array{name: string, kind: 'headline'|'tally'|'table'|'list', heading: string, items: array<mixed>}
 * @phpstan-type Copy array{package: string, title: string, path: string}
 * @phpstan-type Write array{written: list<WrittenRow>, refused: list<Refused>}
 * @phpstan-type Rewrite array{changes: list<Change>, declaration: string}
 */
class Report
{
    /** What the report is introduced by, and what the update hook calls itself. */
    public const LABEL = 'Drupal Patch Check';

    /** The command the hook and the install notice point at for the report. */
    public const COMMAND = 'composer '.CheckCommand::NAME;

    /** The command every next-step suggestion runs. */
    public const REROLL = 'composer '.RerollCommand::NAME;

    /** The command that copies a merge request patch into the site. */
    public const PIN = 'composer '.PinCommand::NAME;

    /**
     * The sections each report prints, in order. A report leaves out a section it has nothing for, and `package` repeats once per package.
     */
    public const SECTIONS = [
        'check' => ['headline', 'warnings', 'unjudged', 'package', 'tally', 'unpinned', 'shipped', 'dropped', 're-rolled', 'conflicted', 'refused', 'rewrite', 'next'],
        'hook' => ['hook'],
        'pin' => ['headline', 'copied', 'held', 'moved', 'not-copied', 'rewrite', 'unpinned'],
        'add' => ['stopped', 'issue', 'took', 'copy', 'dry-run', 'undeclared', 'declared'],
        'candidates' => ['count', 'candidates'],
        'upgrade' => ['stopped', 'move', 'resumed', 'refusals', 'depths', 'layout', 'settings', 'dry-run', 'wrote', 'open', 'decide'],
    ];

    /** What a run on a site whose composer.json an earlier run moved says in place of the move. */
    public const RESUMED = 'composer.json already holds the move, so this run writes nothing';

    /** What a real run that wrote neither document says. */
    public const STAYED = 'nothing written, so the site stays on 1.x';

    /** What a dry run says when a strict refusal's re-roll would not land. */
    public const STOPS = 'a real run stops on the rows that do not re-roll clean and writes nothing';

    /** The narrowest terminal the report is laid out for. */
    public const MIN_WIDTH = 80;

    /** Past this a wider terminal only strands the filename column. */
    public const MAX_WIDTH = 120;

    /** The longest filename column, so the narrowest row still fits. */
    public const TRAILING_MAX = 32;

    /** The number column, right-aligned: room for #99. */
    private const NUMBER_WIDTH = 3;

    /** The verdict column. */
    private const VERDICT_WIDTH = 9;

    /** Where a line about a whole package starts inside its table: past the number column, under the mark. */
    private const UNDER_MARK = self::NUMBER_WIDTH + 1;

    /** Where a line under a table row starts: past the number, the mark and the verdict, each with its space. */
    private const UNDER_TITLE = self::NUMBER_WIDTH + 1 + 2 + self::VERDICT_WIDTH + 1;

    /** A table row's two steps of indent, then the columns before its title. */
    public const PREFIX = 4 + self::UNDER_TITLE;

    /** Between the title and the filename. */
    private const GAP = 2;

    /** Below this a title says nothing, so the row runs long instead. */
    private const MIN_TITLE = 24;

    /** What a shortened string ends with. */
    private const ELLIPSIS = '…';

    /** What the next steps are introduced by. */
    private const NEXT = 'Next:';

    /** Flagged core references printed under a row before the rest is counted. */
    private const CORE_LINES = 3;

    /** Broken lines printed under a row before the rest is counted. One lost brace cascades into every method below it. */
    private const SYNTAX_LINES = 3;

    /** Rows the hook lists before the rest is counted. */
    private const HOOK_ROWS = 20;

    /** The label column of an add run, so the values line up under each other. */
    private const LABEL_WIDTH = 9;

    /** Where the upgrade's settings column starts, so the arrows line up under each other. */
    private const KEY_WIDTH = 24;

    /** The narrowest the upgrade's package column goes, whatever the run holds. */
    private const PACKAGE_WIDTH = 16;

    /** Printed once by a plain run against the installed releases that still has a conflict: the verdict answers what the release has, the installed files do not. */
    private const ON_DISK = 'composer already applied these patches to your files';

    /** Under a row the site declared as a merge request URL, on a site not on 2.x. */
    private const UNPINNED_ROW = 'declared as a merge request URL';

    /** The two lines after the count, wrapped for the narrowest terminal. */
    private const UNPINNED_REST = [
        'account can push to a merge request, so what composer applies here can',
        'change between two installs. Run: '.self::PIN,
    ];

    /** What installing the plugin prints once per site. */
    private const NOTICE = [
        self::LABEL.' is installed. It sends your patch data to api.tresbien.tech.',
        'Review what is sent by running '.self::COMMAND.' --dry-run.',
    ];

    /** What a site on 1.x of the patch manager is told once. */
    private const MOVE = [
        'This site runs cweagans/composer-patches 1.x. 2.x is the line to be on.',
        'It applies with git apply alone and locks a hash per patch.',
        'See what moving costs: composer '.Manager::UPGRADE.' --dry-run',
    ];

    /**
     * Mark, colour tag and sort rank per row status, worst first. A status is the verdict, or the failure mode when the patch applied and left something broken. An unrecognised one gets the fallback and sorts with the work.
     *
     * @var array<string, array{string, string, int}>
     */
    private const STATUSES = [
        PatchRow::BROKEN_SYNTAX => ['!', 'error', 0],
        PatchRow::REFUSED_BY_TWO => ['!', 'error', 0],
        'conflicts' => ['!', 'error', 0],
        'unknown' => ['?', 'comment', 1],
        'applies' => ['·', '', 2],
        'merged' => ['✓', 'info', 3],
    ];

    /** @var array{string, string, int} */
    private const UNRECOGNISED = ['*', 'comment', 1];

    /** The statuses the hook's headline counts, worst first, in the words the report's tally uses. */
    private const MENTION_ORDER = [PatchRow::BROKEN_SYNTAX, 'conflicts', 'unknown', 'merged'];

    /**
     * Every section as the lines it prints.
     *
     * @param list<Section> $sections
     *
     * @return list<string>
     */
    public static function print(array $sections): array
    {
        $lines = [];
        foreach ($sections as $section) {
            $level = 'headline' === $section['kind'] ? 0 : 1;
            // Every section but a headline opens with a blank line.
            if (1 === $level) {
                $lines[] = '';
            }
            if ('' !== $section['heading']) {
                $lines[] = self::indent($level).$section['heading'];
                // A tally's items qualify the count, so they keep its level.
                $level += 'tally' === $section['kind'] ? 0 : 1;
            }
            \array_push($lines, ...self::nested($section['items'], $level));
        }

        return $lines;
    }

    /**
     * What a check or a re-roll run prints: the table a plain run answers with, or what a re-roll run did.
     *
     * @param Coverage     $coverage
     * @param Write|null   $write    what a re-roll run wrote; such a run prints neither the rows nor the skipped packages
     * @param Rewrite|null $rewrite  the declaration rewrite a re-roll run made
     * @param list<string> $scope    the options a next run repeats, `--target 11.4.5` and each `--package`
     *
     * @return list<Section>
     */
    public static function check(Plan $plan, array $coverage, ?array $write, ?array $rewrite, int $width, array $scope): array
    {
        $vacuous = 0 === $coverage['checked'] && [] !== $coverage['skipped'];
        $packages = [];
        foreach ($plan->numbered() as [$row, $number]) {
            $packages[$row->package][$number] = $row;
        }
        $tables = [];
        // A run that writes is about the files it wrote. The table is the
        // plain run's answer, and repeating it there buries the footer.
        if (null === $write) {
            $titleWidth = self::title($width, \max([0, ...\array_map(static fn (PatchRow $row): int => \mb_strlen(self::fileName($row)), $plan->rows())]));
            foreach ($packages as $package => $rows) {
                $heading = Text::t('@heading   @tally', ['@heading' => self::packageHeading($rows[1]), '@tally' => self::packageTally($rows)]);
                \array_push($tables, ...self::section('package', 'table', $heading, self::packageRows($plan, $coverage, (string) $package, $rows, $titleWidth)));
            }
        }
        $dropped = [];
        foreach ($rewrite['changes'] ?? [] as $change) {
            if (Declarations::DROPPED === $change['action']) {
                $dropped[Declaration::keyOf($change['package'], $change['title'])] = true;
            }
        }
        $shipped = ['shipped' => [], 'dropped' => []];
        $refused = [];
        foreach ($write['refused'] ?? [] as $refusal) {
            if (!$refusal['shipped']) {
                $refused[] = $refusal;
                continue;
            }
            $group = isset($dropped[Declaration::keyOf($refusal['package'], $refusal['title'])]) ? 'dropped' : 'shipped';
            \array_push($shipped[$group], Text::t('@package: @title', ['@package' => $refusal['package'], '@title' => $refusal['title']]), [$refusal['path']]);
        }
        $files = ['re-rolled' => [], 'conflicted' => []];
        foreach ($write['written'] ?? [] as $file) {
            \array_push($files[PatchRow::CONFLICTS === $file['status'] ? 'conflicted' : 're-rolled'], ...self::writtenFile($file));
        }
        $tally = [];
        // A write run has just put re-rolls on disk that composer applied
        // nothing of, and a target run judges a release the site does not
        // install, so the caveat is the plain installed run's alone.
        if (null === $write && $plan->targetIsInstalled && ($plan->counts()[PatchRow::CONFLICTS] ?? 0) > 0) {
            $tally[] = self::tagged(self::ON_DISK, 'comment');
        }
        // A path the service says it never received that the run did not
        // hold back: the text was lost rather than kept back on purpose.
        $lost = \array_values(\array_diff($plan->missingFiles, \array_column($coverage['unsent'], 'source')));
        if ([] !== $lost) {
            $tally[] = Text::t('patch text not sent for: @sources', ['@sources' => \implode(', ', $lost)]);
        }
        $headline = $vacuous
            ? self::tagged(self::LABEL.': no patch could be checked; the reasons are below', 'comment')
            : self::tagged(self::LABEL, 'info').': '.Text::plural(\count($plan->rows()), '@count patch @scenario', '@count patches @scenario', ['@scenario' => $plan->scenario()]);

        return self::ordered('check', [
            'headline' => self::section('headline', 'headline', $headline, []),
            'warnings' => self::listed('warnings', '', \array_map(static fn (string $warning): string => self::tagged(Text::t('! @warning', ['@warning' => $warning]), 'comment'), $plan->warnings())),
            // A run that writes did nothing about the skipped packages, unless they are all it has to say.
            'unjudged' => null === $write || $vacuous ? self::listed('unjudged', '', \array_map(static fn (string $line): string => self::tagged($line, 'comment'), self::unjudged($coverage, $packages))) : [],
            'package' => $tables,
            'tally' => self::section('tally', 'tally', Text::t('patches: @tally', ['@tally' => null === $write ? self::tally(self::headlineCounts($plan)) : self::writeTally($plan, $write, $rewrite)]), $tally),
            'unpinned' => self::unpinnedWarning($plan->manager, \count(self::unpinned($plan))),
            'shipped' => self::listed('shipped', 'already in the release, drop it:', $shipped['shipped']),
            'dropped' => self::listed('dropped', 'already in the release, dropped:', $shipped['dropped']),
            're-rolled' => self::listed('re-rolled', 're-rolled:', $files['re-rolled']),
            'conflicted' => self::listed('conflicted', 're-rolled with conflicts:', $files['conflicted']),
            'refused' => self::listed('refused', 'not re-rolled:', self::byReason($refused, '', static fn (array $refusal): string => Text::t('@path  @package: @title', ['@path' => $refusal['path'], '@package' => $refusal['package'], '@title' => $refusal['title']]))),
            'rewrite' => null === $rewrite ? [] : self::section('rewrite', 'list', [] === $rewrite['changes']
                ? Text::t('@file is unchanged: nothing to delete, and every re-roll was saved over the old file', ['@file' => $rewrite['declaration']])
                : Text::t('@file:', ['@file' => $rewrite['declaration']]), \array_map(self::change(...), $rewrite['changes'])),
            'next' => self::listed('next', '', self::nextStepLines($plan->counts(), $write, $rewrite, $scope)),
        ]);
    }

    /**
     * What the post-update hook prints: the verdict tally, the patches that need attention, and any caveat on the counts, as one headline.
     *
     * @return list<Section>
     */
    public static function hook(Plan $plan): array
    {
        $rows = $plan->worthMentioning();
        // Composer applies a package's patches during the update, and this
        // hook runs after it. A patch that still applies is something
        // composer has already proved; what composer cannot say is that a
        // patch can be deleted.
        if ([] === $rows) {
            return [];
        }
        $items = [];
        foreach ($plan->warnings() as $warning) {
            $items[] = self::tagged(Text::t('! @warning', ['@warning' => $warning]), 'comment');
        }
        // A package the service answered no row for is not this hook's business.
        foreach ($plan->packages() as $package) {
            if ('' !== ($plan->rowNotes[$package] ?? '')) {
                $items[] = self::tagged(Text::t('! @package @note', ['@package' => $package, '@note' => $plan->rowNotes[$package]]), 'comment');
            }
        }
        foreach (self::capped(\array_map(self::hookRow(...), $rows), self::HOOK_ROWS, \count($rows), '+@count more patch', '+@count more patches') as $row) {
            \array_push($items, ...(\is_array($row) ? $row : [$row]));
        }
        $items[] = Text::t('run `@command` for the detail, or `--target <version>` before a core upgrade', ['@command' => self::COMMAND]);

        return self::ordered('hook', [
            'hook' => self::section('hook', 'headline', self::tagged(self::LABEL, 'info').': '.self::hookHeadline($rows), [...$items, ...self::nextStepLines($plan->counts(), null, null, [])]),
        ]);
    }

    /**
     * What a pin run prints: what it copied into the site, what was there already, what moved upstream, what it could not copy, and the declarations it rewrote.
     *
     * @param array{vendored: list<Copy>, kept: list<Copy>, moved: list<Copy>, refused: list<array{package: string, title: string, reason: string}>} $result
     * @param string                                                                                                                                 $declaration the documents the run rewrote, empty when it rewrote none
     * @param int                                                                                                                                    $unpinned    declarations still declared as a merge request URL once the run is over
     * @param Manager                                                                                                                                $manager     the patch manager the site runs
     *
     * @return list<Section>
     */
    public static function pin(array $result, string $declaration, int $rewritten, int $unpinned, Manager $manager): array
    {
        $parts = [];
        foreach (['vendored' => 'copied into the site', 'kept' => 'already in the site', 'moved' => 'moved upstream', 'refused' => 'not copied'] as $list => $words) {
            if ([] !== $result[$list]) {
                $parts[] = Text::plural(\count($result[$list]), '@count patch @words', '@count patches @words', ['@words' => $words]);
            }
        }
        $copies = static fn (array $rows): array => \array_merge(...\array_map(
            static fn (array $row): array => [Text::t('@package: @title', ['@package' => $row['package'], '@title' => $row['title']]), [$row['path']]],
            $rows,
        ));

        return self::ordered('pin', [
            'headline' => self::section('headline', 'headline', self::tagged(self::LABEL, 'info').': '.([] === $parts ? 'no patch is declared from a merge request' : \implode(', ', $parts)), []),
            'copied' => self::listed('copied', 'copied into the site:', $copies($result['vendored'])),
            'held' => self::listed('held', 'already in the site:', $copies($result['kept'])),
            'moved' => [] === $result['moved'] ? [] : self::section('moved', 'list', '', [
                'moved since you copied it:',
                $copies($result['moved']),
                Text::t('run `@command --refresh` to take the new commits', ['@command' => self::PIN]),
            ]),
            'not-copied' => self::listed('not-copied', 'not copied:', self::byReason($result['refused'], 'comment', static fn (array $row): string => Text::t('@package: @title', ['@package' => $row['package'], '@title' => $row['title']]))),
            'rewrite' => 0 === $rewritten || '' === $declaration ? [] : self::section('rewrite', 'list', Text::plural(
                $rewritten,
                '@file: @count declaration now points at a file in the site',
                '@file: @count declarations now point at files in the site',
                ['@file' => $declaration]
            ), []),
            'unpinned' => self::unpinnedWarning($manager, $unpinned),
        ]);
    }

    /**
     * What an add run prints: the issue it resolved, the copy it made, the verdict, then whether it declared the patch.
     *
     * @param Outcome $result
     *
     * @return list<Section>
     */
    public static function add(array $result): array
    {
        if ('' !== $result['error']) {
            return self::ordered('add', ['stopped' => self::section('stopped', 'list', self::tagged($result['error'], 'error'), [])]);
        }
        $copy = [
            self::pad('vendored', self::LABEL_WIDTH).$result['path'],
            self::pad('verdict', self::LABEL_WIDTH).('' === $result['reason'] ? $result['verdict'] : Text::t('@verdict, @reason', ['@verdict' => $result['verdict'], '@reason' => $result['reason']])),
        ];
        if ('' !== $result['wrote'] && $result['wrote'] !== $result['path']) {
            $copy[] = self::pad('re-roll', self::LABEL_WIDTH).$result['wrote'];
        }
        foreach ($result['regions'] as $region) {
            $copy[] = self::pad('', self::LABEL_WIDTH).$region;
        }
        $wrote = !$result['dryRun'];

        return self::ordered('add', [
            'issue' => self::section('issue', 'list', Text::t('@package, issue @issue', ['@package' => $result['package'], '@issue' => $result['issue']]), []),
            'took' => [] === $result['candidates'] ? [] : self::section('took', 'list', Text::t('took @label', ['@label' => self::candidate($result['candidates'][$result['chosen']])]), []),
            'copy' => self::section('copy', 'table', '', $copy),
            'dry-run' => $wrote ? [] : self::section('dry-run', 'list', 'nothing written (--dry-run)', []),
            'undeclared' => $wrote && !$result['declared'] ? self::section('undeclared', 'list', self::undeclared($result), []) : [],
            'declared' => $wrote && $result['declared'] ? self::section('declared', 'list', Text::t('declared @title', ['@title' => $result['title']]), []) : [],
        ]);
    }

    /**
     * The merge requests on one issue, in the order they are offered, for a person about to pick.
     *
     * @param non-empty-list<Candidate> $ordered
     *
     * @return list<Section>
     */
    public static function candidates(array $ordered, string $issue): array
    {
        return self::ordered('candidates', [
            'count' => self::section('count', 'list', Text::plural(\count($ordered), '@count merge request on issue @issue', '@count merge requests on issue @issue', ['@issue' => $issue]), []),
            'candidates' => self::section('candidates', 'table', '', \array_map(
                static fn (int $at): string => Text::t('[@at] @candidate', ['@at' => $at, '@candidate' => self::candidate($ordered[$at])]),
                \array_keys($ordered),
            )),
        ]);
    }

    /**
     * What a person is asked once the candidates are listed.
     */
    public static function question(): string
    {
        return self::indent(1).'Which one?';
    }

    /**
     * What a manager upgrade prints: the patches the move costs, the depths it records, the layout it changes, the settings it moves, then what it wrote or what a real run finishes with.
     *
     * @param MoveReport $move
     *
     * @return list<Section>
     */
    public static function upgrade(array $move): array
    {
        if ('' !== $move['error']) {
            return self::ordered('upgrade', ['stopped' => self::section('stopped', 'list', self::tagged($move['error'], 'error'), [])]);
        }
        $head = self::section('move', 'list', Text::t('@package @from -> @to', ['@package' => Manager::PACKAGE, '@from' => $move['from'], '@to' => Declarations::CONSTRAINT]), []);
        if ($move['resumed']) {
            return self::ordered('upgrade', ['move' => $head, 'resumed' => self::section('resumed', 'list', '', [self::RESUMED, ...(null === $move['wrote'] ? self::finishes() : [])])]);
        }
        // Both tables share one pair of widths, so a package named in each
        // sits at the same column in both.
        $listed = [...$move['refused'], ...$move['depths']];
        $packageWidth = \max([self::PACKAGE_WIDTH, ...\array_map(static fn (array $row): int => \mb_strlen($row['package']), $listed)]);
        $numberWidth = \max([1, ...\array_map(static fn (array $row): int => \strlen((string) $row['number']), $listed)]);
        $at = static fn (array $row): string => self::pad($row['package'], $packageWidth).'  '.self::pad('#'.$row['number'], $numberWidth + 1, true);
        $settings = [];
        foreach ($move['renamed'] as $from => $to) {
            $settings[] = Text::t('@from -> extra.@key.@to', ['@from' => self::pad('extra.'.$from, self::KEY_WIDTH), '@key' => Settings::KEY, '@to' => $to]);
        }
        foreach ($move['dropped'] as $from => $why) {
            $settings[] = Text::t('@from dropped, @why', ['@from' => self::pad('extra.'.$from, self::KEY_WIDTH), '@why' => $why]);
        }
        $open = null === $move['wrote'] ? [] : $move['wrote']['open'];

        return self::ordered('upgrade', [
            'move' => $head,
            'refusals' => self::section('refusals', 'list', '', self::refusals($move, $at)),
            'depths' => self::listed('depths', Text::plural(
                \count($move['depths']),
                '@count patch applies at a depth other than its package default:',
                '@count patches apply at a depth other than their package default:'
            ), \array_map(static fn (array $row): string => Text::t('@at  -p@depth', ['@at' => $at($row), '@depth' => $row['depth']]), $move['depths'])),
            // An edit to composer.json invalidates composer.lock's content
            // hash, so the declarations move out of it once.
            'layout' => self::section('layout', 'table', 'layout:', [
                Text::t('@from -> @file', ['@from' => self::pad('extra.patches', self::KEY_WIDTH), '@file' => $move['file']]),
                // Under the file it moves to, at the column the arrow left it in.
                self::pad('', self::KEY_WIDTH + 4).Text::t('extra.@key.patches-file', ['@key' => Settings::KEY]),
            ]),
            'settings' => self::listed('settings', 'settings:', $settings),
            'dry-run' => null === $move['wrote'] ? self::section('dry-run', 'list', '', ['nothing written (--dry-run)', ...self::finishes()]) : [],
            'wrote' => null === $move['wrote'] ? [] : self::listed('wrote', '', self::wrote($move['wrote'])),
            'open' => self::listed('open', self::tagged(Text::plural(
                \count($open),
                '@count re-roll left regions to decide, so nothing else was written:',
                '@count re-rolls left regions to decide, so nothing else was written:'
            ), 'comment'), \array_map(static fn (array $row): string => Text::plural($row['regions'], '@path  @count region', '@path  @count regions', ['@path' => $row['path']]), $open)),
            'decide' => [] === $open ? [] : self::section('decide', 'list', Text::t('decide them, run `@reroll`, then run this command again', ['@reroll' => self::REROLL]), []),
        ]);
    }

    /**
     * The line that closes a move whose manager commands all finished.
     *
     * @return list<Section>
     */
    public static function moved(): array
    {
        return self::section('moved', 'list', Text::t('moved to @package 2.x', ['@package' => Manager::PACKAGE]), []);
    }

    /**
     * Why the manager's commands stopped and what a person runs to finish, nothing for a run that finished.
     *
     * @param Ran $ran
     *
     * @return list<Section>
     */
    public static function unfinished(array $ran): array
    {
        if ('' === $ran['error']) {
            return [];
        }
        $left = \array_map(static fn (string $command): string => '`composer '.$command.'`', $ran['left']);

        return self::section('unfinished', 'list', '', [self::tagged($ran['error'], 'error'), Text::t('run @commands to finish', ['@commands' => \implode(' then ', $left)])]);
    }

    /**
     * The line printed before a manager command starts, with the child's output below it.
     */
    public static function running(string $command): string
    {
        return self::indent(1).Text::t('-> composer @command', ['@command' => $command]);
    }

    /**
     * One note from a run, outside any report: `error` for a run that stops, `comment` for one that goes on.
     */
    public static function message(string $message, string $tag): string
    {
        return self::tagged(Text::t('drupatch: @message', ['@message' => $message]), $tag);
    }

    /**
     * What installing the plugin prints once per site: what leaves it, and how to read the request first.
     *
     * @return list<Section>
     */
    public static function notice(): array
    {
        return \array_merge(...\array_map(static fn (string $line): array => self::section('notice', 'headline', self::tagged($line, 'info'), []), self::NOTICE));
    }

    /**
     * What a site on 1.x of the patch manager is told once, nothing for a site already on 2.x or with no patch manager.
     *
     * @return list<Section>
     */
    public static function movePrompt(Manager $manager): array
    {
        return $manager->isOne() ? \array_merge(...\array_map(static fn (string $line): array => self::section('move', 'headline', self::tagged($line, 'comment'), []), self::MOVE)) : [];
    }

    /**
     * The warning about declarations anyone with a drupal.org account can change: how many, who can change them, and the command. Nothing on 2.x, which records a hash per patch and refuses bytes that moved.
     *
     * @param Manager|null $manager the patch manager the site runs, null for a plan read as the service sent it
     *
     * @return list<Section>
     */
    public static function unpinnedWarning(?Manager $manager, int $count): array
    {
        if (0 === $count || true === $manager?->isTwo()) {
            return [];
        }
        $first = Text::t('@sentence Anyone with a drupal.org', ['@sentence' => Text::plural($count, '@count patch is declared as a merge request URL.', '@count patches are declared as merge request URLs.')]);

        return self::section('unpinned', 'list', '', \array_map(static fn (string $line): string => '<fg=red>'.$line.'</>', [$first, ...self::UNPINNED_REST]));
    }

    /**
     * The rows whose declared source is a merge request, whose bytes anyone with a drupal.org account can change.
     *
     * @return list<PatchRow>
     */
    public static function unpinned(Plan $plan): array
    {
        return \array_values(\array_filter($plan->rows(), static fn (PatchRow $row): bool => null !== MergeRequest::of($row->source)));
    }

    /**
     * The `--format=json` document: the service's answer with the diff text this run put on disk taken out, then the summary, the files written and the refusals.
     *
     * @param Write|null   $write
     * @param Rewrite|null $rewrite
     *
     * @return array<string, mixed>
     */
    public static function json(Plan $plan, ?array $write, ?array $rewrite): array
    {
        $document = $plan->document();
        $paths = [];
        foreach ($write['written'] ?? [] as $file) {
            $paths[Declaration::keyOf($file['package'], $file['title'])] = $file['path'];
        }
        // A written row names the file in place of its diff; a refused row keeps its text.
        foreach ((array) ($document['plan']['patches'] ?? []) as $i => $row) {
            $path = $paths[Declaration::keyOf((string) $row['package'], (string) ($row['title'] ?? ''))] ?? null;
            if (null !== $path && \is_array($row['result']['reroll'] ?? null)) {
                $document['plan']['patches'][$i]['result']['reroll']['patch'] = '';
                $document['plan']['patches'][$i]['result']['reroll']['path'] = $path;
            }
        }

        return $document + ['summary' => self::summary($plan, $write, $rewrite)] + [
            'written' => \array_map(static fn (array $file): array => ['path' => $file['path'], 'status' => $file['status']], $write['written'] ?? []),
        ] + [
            'refused' => \array_map(static fn (array $refusal): array => ['path' => $refusal['path'], 'reason' => $refusal['reason']], $write['refused'] ?? []),
        ];
    }

    /**
     * The `--format=json` summary a scheduled job reads: what the run was about, what it found, which packages are behind each finding, and what to run next.
     *
     * @param Write|null   $write
     * @param Rewrite|null $rewrite
     *
     * @return array<string, mixed>
     */
    public static function summary(Plan $plan, ?array $write, ?array $rewrite): array
    {
        $counts = [];
        $sources = [];
        foreach ($plan->rows() as $row) {
            $counts[$row->status()] = ($counts[$row->status()] ?? 0) + 1;
            if ('' !== $row->decidedBy) {
                $sources[$row->decidedBy] = ($sources[$row->decidedBy] ?? 0) + 1;
            }
        }
        \ksort($counts);
        \ksort($sources);

        $summary = [
            'target_core' => $plan->targetCore,
            'target_is_installed' => $plan->targetIsInstalled,
            'counts' => $counts,
            'conflicts' => self::packagesWith($plan, PatchRow::CONFLICTS),
            'unclear' => self::packagesWith($plan, PatchRow::UNKNOWN),
            'merged' => self::packagesWith($plan, PatchRow::MERGED),
            'broken' => self::packagesWith($plan, PatchRow::BROKEN_SYNTAX),
            'blocked' => $plan->noRelease(),
            'decided_by' => $sources,
            'exit_code' => $plan->exitCode(),
        ];
        if ('' !== $plan->targetFrom) {
            $summary['target_from'] = $plan->targetFrom;
        }
        $unpinned = \array_map(static fn (PatchRow $row): array => ['package' => $row->package, 'title' => $row->title, 'source' => $row->source], self::unpinned($plan));
        if ([] !== $unpinned) {
            $summary['unpinned'] = $unpinned;
        }
        $next = self::nextSteps($counts, $write, $rewrite);
        if ([] !== $next) {
            $summary['next'] = $next;
        }

        return $summary;
    }

    /**
     * The commands worth running, worst finding first.
     *
     * @param array<string, int> $counts  patches per verdict
     * @param Write|null         $write   what the run wrote, null for a run that wrote nothing
     * @param Rewrite|null       $rewrite the declaration rewrite the run made
     *
     * @return list<array{command: string, flag: string, effect: string}>
     */
    public static function nextSteps(array $counts, ?array $write, ?array $rewrite): array
    {
        $steps = [];
        $shipped = $counts[PatchRow::MERGED] ?? 0;
        $refused = $write['refused'] ?? [];
        if (null === $write) {
            $conflicts = $counts[PatchRow::CONFLICTS] ?? 0;
            if ($conflicts > 0 || $shipped > 0) {
                $steps[] = ['command' => self::REROLL, 'flag' => '', 'effect' => self::rerollEffect($conflicts, $shipped)];
            }
        } else {
            // A conflict file holding only a removed file asks nothing, so
            // offering to send its decisions would point at no work.
            $open = \count(\array_filter($write['written'], static fn (array $file): bool => PatchRow::CONFLICTS === $file['status'] && $file['regions'] > 0));
            if ($open > 0) {
                $steps[] = ['command' => self::REROLL, 'flag' => '', 'effect' => Text::plural($open, 'sends the regions you decide in the conflict file', 'sends the regions you decide in the @count conflict files')];
            }
            $forcible = \count(\array_filter($refused, static fn (array $refusal): bool => '--force' === $refusal['lifts']));
            if ($forcible > 0) {
                $steps[] = ['command' => self::REROLL, 'flag' => '--force', 'effect' => Text::plural($forcible, 'replaces the file this run would not overwrite', 'replaces the @count files this run would not overwrite')];
            }
            // A run that rewrote the declarations dropped what shipped, so
            // offering it again would repeat what just happened.
            if (null === $rewrite && $shipped > 0) {
                $steps[] = ['command' => self::REROLL, 'flag' => '', 'effect' => self::rerollEffect(0, $shipped)];
            }
        }
        $urls = \count(\array_filter($refused, static fn (array $refusal): bool => PatchFiles::URL_DECLARED === $refusal['reason']));
        if ($urls > 0) {
            $steps[] = ['command' => self::PIN, 'flag' => '', 'effect' => Text::plural($urls, 'copies the patch declared as a URL into the site', 'copies the @count patches declared as URLs into the site')];
        }

        return $steps;
    }

    /**
     * The next steps as printed, one command each with its effect in a column, empty when there is nothing to run.
     *
     * @param array<string, int> $counts  patches per verdict
     * @param Write|null         $write
     * @param Rewrite|null       $rewrite
     * @param list<string>       $scope   the options a next run repeats, between the command and the flag
     *
     * @return list<string>
     */
    public static function nextStepLines(array $counts, ?array $write, ?array $rewrite, array $scope): array
    {
        $steps = self::nextSteps($counts, $write, $rewrite);
        $commands = \array_map(static fn (array $step): string => \implode(' ', \array_filter([$step['command'], ...$scope, $step['flag']], static fn (string $part): bool => '' !== $part)), $steps);
        $widest = \max([0, ...\array_map(\mb_strlen(...), $commands)]);
        $lines = [];
        foreach ($steps as $i => $step) {
            $lines[] = self::pad(0 === $i ? self::NEXT : '', \strlen(self::NEXT)).'  '.self::pad($commands[$i], $widest).'   '.$step['effect'];
        }

        return $lines;
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
     * `$text` padded with spaces to `$width` characters: on the right, or on the left for a column of numbers.
     */
    public static function pad(string $text, int $width, bool $numbers = false): string
    {
        $fill = \str_repeat(' ', \max(0, $width - \mb_strlen($text)));

        return $numbers ? $fill.$text : $text.$fill;
    }

    /**
     * The mark ready to print, wrapped in its colour when it has one.
     */
    public static function marked(string $status): string
    {
        [$mark, $tag] = self::STATUSES[$status] ?? self::UNRECOGNISED;

        return self::tagged($mark, $tag);
    }

    /**
     * What a row is called: its title, or its source when it has none.
     */
    public static function label(PatchRow $row): string
    {
        return '' !== $row->title ? $row->title : $row->source;
    }

    /**
     * Why a patch has no usable verdict, empty when it has one.
     */
    public static function reason(PatchRow $row): string
    {
        return '' !== $row->note ? $row->note : $row->error;
    }

    /**
     * The files a re-roll left out of the merge.
     */
    public static function droppedPathsNote(int $files): string
    {
        return Text::plural(
            $files,
            'the re-roll left out @count file your release does not ship, so your patch holds the fix alone',
            'the re-roll left out @count files your release does not ship, so your patch holds the fix alone'
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
        $flagged = \array_map(self::finding(...), \array_values((array) ($block['flagged'] ?? [])));
        $out = self::capped($flagged, self::CORE_LINES, $row->flaggedCoreReferences(), '+@count more core reference', '+@count more core references');
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
        // broken row already printed the parse errors this note repeats.
        $note = (string) ($block['note'] ?? '');
        if ('' !== $note && !$row->conflicts() && '' === $row->failureMode()) {
            $out[] = $note;
        }

        return $out;
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private static function nested(array $items, int $level): array
    {
        $lines = [];
        foreach ($items as $item) {
            \array_push($lines, ...(\is_array($item) ? self::nested($item, $level + 1) : [self::indent($level).$item]));
        }

        return $lines;
    }

    /**
     * The indent ladder: two spaces a step.
     */
    private static function indent(int $level): string
    {
        return \str_repeat('  ', $level);
    }

    /**
     * @param 'headline'|'tally'|'table'|'list' $kind
     * @param array<mixed>                      $items
     *
     * @return list<Section>
     */
    private static function section(string $name, string $kind, string $heading, array $items): array
    {
        return [['name' => $name, 'kind' => $kind, 'heading' => $heading, 'items' => $items]];
    }

    /**
     * A titled list, left out when it has no items.
     *
     * @param array<mixed> $items
     *
     * @return list<Section>
     */
    private static function listed(string $name, string $heading, array $items): array
    {
        return [] === $items ? [] : self::section($name, 'list', $heading, $items);
    }

    /**
     * One report's sections in the order `SECTIONS` names them.
     *
     * @param array<string, list<Section>> $built
     *
     * @return list<Section>
     */
    private static function ordered(string $report, array $built): array
    {
        $out = [];
        foreach (self::SECTIONS[$report] as $name) {
            \array_push($out, ...($built[$name] ?? []));
        }

        return $out;
    }

    /**
     * The first `$cap` items, then a line counting the rest. `$total` counts every item, the ones the service never sent included.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T|string>
     */
    private static function capped(array $items, int $cap, int $total, string $one, string $many): array
    {
        $shown = \array_slice($items, 0, $cap);
        $more = $total - \count($shown);

        return $more > 0 ? [...$shown, Text::plural($more, $one, $many)] : $shown;
    }

    private static function tagged(string $text, string $tag): string
    {
        return '' === $tag ? $text : '<'.$tag.'>'.$text.'</'.$tag.'>';
    }

    /**
     * Rows under the reason they carry, each reason heading its group where its first row falls. Printed after the rows it explains, a reason reads as though the ones above it have none.
     *
     * @param list<array{reason: string}>           $rows
     * @param Closure(array<string, mixed>): string $line
     *
     * @return list<string|list<string>>
     */
    private static function byReason(array $rows, string $tag, Closure $line): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['reason']][] = $line($row);
        }
        $items = [];
        foreach ($groups as $reason => $lines) {
            \array_push($items, self::tagged((string) $reason, $tag), $lines);
        }

        return $items;
    }

    /**
     * One package's table rows: what keeps its release back, each patch numbered in the order composer applies them over the lines under it, then what the run skipped.
     *
     * @param Coverage                       $coverage
     * @param non-empty-array<int, PatchRow> $rows     by patch number
     *
     * @return list<string>
     */
    private static function packageRows(Plan $plan, array $coverage, string $package, array $rows, int $titleWidth): array
    {
        $lines = [];
        if ('' !== ($plan->rowNotes[$package] ?? '')) {
            $lines[] = self::pad('', self::UNDER_MARK).self::tagged(Text::t('! @warning', ['@warning' => $plan->rowNotes[$package]]), 'comment');
        }
        foreach ($rows as $number => $row) {
            $lines[] = \rtrim(self::pad('#'.$number, self::NUMBER_WIDTH, true).' '.self::marked($row->status()).' '.self::pad($row->verdict, self::VERDICT_WIDTH).' '.self::pad(self::fit(self::label($row), $titleWidth), $titleWidth + self::GAP).self::fileName($row));
            $under = \array_map(static fn (string $line): string => '<fg=cyan>'.$line.'</>', self::details($row));
            if (null !== MergeRequest::of($row->source) && true !== $plan->manager?->isTwo()) {
                \array_unshift($under, '<fg=red>'.self::UNPINNED_ROW.'</>');
            }
            foreach ($under as $line) {
                $lines[] = self::pad('', self::UNDER_TITLE).$line;
            }
        }
        foreach (self::coverageNotes($coverage, $package) as $note) {
            $lines[] = self::pad('', self::UNDER_MARK).self::tagged($note, 'comment');
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
        $out = '' === self::reason($row) ? [] : [self::reason($row)];
        $broken = \array_map(static fn (string $error): string => Text::t('@mode: @error', ['@mode' => $row->failureMode(), '@error' => $error]), $row->syntaxErrors);
        \array_push($out, ...self::capped($broken, self::SYNTAX_LINES, \count($broken), '+@count more broken line', '+@count more broken lines'));
        // Hunks are keyed by place, so two failures at one place print once
        // and a failure pairs with a shipped hunk at the same place.
        $shipped = \array_fill_keys(\array_map(self::place(...), $row->hunksShipped), true);
        $failed = [];
        foreach ($row->failures() as $hunk) {
            $place = self::place($hunk);
            // A hunk the release already has is why the patch stopped
            // applying there, so the two lines about it become one.
            $failed[$place] = isset($shipped[$place]) ? Text::t('@place: already in the release, not needed', ['@place' => $place]) : self::hunk($hunk);
        }
        // Only a conflicting row lists hunks, and the service capped the list.
        \array_push($out, ...self::capped(\array_values($failed), \count($failed), $row->conflicts() ? $row->failedTotal : 0, '+@count more failed hunk', '+@count more failed hunks'));
        $unpaired = [];
        foreach ($row->hunksShipped as $hunk) {
            if (!isset($failed[self::place($hunk)])) {
                $unpaired[] = Text::t('already in the release: @place', ['@place' => self::place($hunk)]);
            }
        }
        $total = $row->shippedTotal - \count($row->hunksShipped) + \count($unpaired);
        \array_push($out, ...self::capped($unpaired, \count($unpaired), $total, '+@count more hunk already in the release', '+@count more hunks already in the release'));
        // A plain run does not ask for a re-roll, and a re-roll is what
        // finds a patch the release has whole. Say so where part of one is
        // already there.
        if ($row->conflicts() && [] !== $row->hunksShipped) {
            $out[] = Text::t('run @command to see if the release has the rest', ['@command' => self::REROLL]);
        }
        $reroll = $row->reroll;
        if (null !== $reroll && '' !== $reroll->mergedFrom) {
            $cut = \strrpos($reroll->mergedFrom, '/-/');
            $tail = false === $cut ? $reroll->mergedFrom : \substr($reroll->mergedFrom, $cut + 3);
            $out[] = Text::t('merged from @patch; the verdict used your declared file', ['@patch' => '' === $tail ? $reroll->mergedFrom : $tail]);
        }
        if (null !== $reroll && [] !== $reroll->unioned) {
            $out[] = self::unionNote(\count($reroll->unioned));
        }
        if (null !== $reroll && [] !== $reroll->droppedPaths) {
            $out[] = self::droppedPathsNote(\count($reroll->droppedPaths));
        }
        // The service's reason speaks for a manager that applies loosely,
        // which a site on 2.x does not run.
        if (PatchRow::REFUSED_BY_TWO === $row->failureMode()) {
            $out[] = Text::t('your patch manager refuses it; `@reroll` writes a version it applies', ['@reroll' => self::REROLL]);
        } elseif ('' !== $row->strictRefused) {
            $out[] = $row->strictRefused;
        }
        if ([] !== $row->judgedWithout) {
            $out[] = self::judgedWithoutNote($row->judgedWithout);
        }

        return [...$out, ...self::coreReferenceLines($row)];
    }

    /**
     * One failed hunk as a person reads it: where it is, and why.
     *
     * @param Hunk $hunk
     */
    private static function hunk(array $hunk): string
    {
        $place = self::place($hunk);
        if ('' === $place || '' === $hunk['reason']) {
            return $place.$hunk['reason'];
        }

        return Text::t('@place: @reason', ['@place' => $place, '@reason' => $hunk['reason']]);
    }

    /**
     * Where a hunk is: its file, and the release line it was aimed at.
     *
     * @param Hunk $hunk
     */
    private static function place(array $hunk): string
    {
        return '' === $hunk['file'] || $hunk['line'] <= 0 ? $hunk['file'] : Text::t('@file:@line', ['@file' => $hunk['file'], '@line' => $hunk['line']]);
    }

    /**
     * The earlier patches a row was judged behind, each cited by the number the rows are printed with.
     *
     * @param list<string> $labels
     */
    private static function judgedWithoutNote(array $labels): string
    {
        // Server JSON is the boundary: a label that is not a row number is
        // printed as it came.
        $cited = \array_map(static fn (string $label): string => 1 === \preg_match('/^#\d+$/', $label) ? $label : Text::t('"@label"', ['@label' => $label]), $labels);
        $last = (string) \array_pop($cited);
        $patches = [] === $cited ? $last : Text::t('@earlier and @last', ['@earlier' => \implode(', ', $cited), '@last' => $last]);

        return Text::t('judged after @patches applied in part', ['@patches' => $patches]);
    }

    /**
     * One flagged core reference.
     *
     * @param array<string, mixed> $finding
     */
    private static function finding(array $finding): string
    {
        // Server JSON is the boundary: a finding without the sentence still
        // gives its symbol.
        $what = (string) ($finding['issue'] ?? '');
        $record = (int) ($finding['change_record'] ?? 0);

        return Text::t($record > 0 ? 'core @kind: @what (change record @record)' : 'core @kind: @what', [
            '@kind' => (string) ($finding['kind'] ?? ''),
            '@what' => '' === $what ? (string) ($finding['symbol'] ?? '') : $what,
            '@record' => $record,
        ]);
    }

    /**
     * What the merge decided on its own, in one line.
     */
    private static function unionNote(int $regions): string
    {
        return Text::plural($regions, 'the merge kept both additions in @count region, check it', 'the merge kept both additions in @count regions, check them');
    }

    /**
     * One written file over what a person needs to know about it: the regions a decision addresses, what the release removed, where the copy came from, what the re-roll left out and what the merge decided.
     *
     * @param WrittenRow $file
     *
     * @return array{string, list<string|list<string>>}
     */
    private static function writtenFile(array $file): array
    {
        // A decision gives its region by file and index.
        $under = \array_map(static fn (array $region): string => Text::t('@file region @region', ['@file' => $region['file'], '@region' => $region['region']]), $file['open']);
        // With regions of its own to show, the status line gives the count,
        // so a removed file needs its own line here.
        if ($file['regions'] > 0) {
            foreach ($file['removed'] as $gone) {
                $under[] = Text::t('the release removed @file', ['@file' => $gone]);
            }
        }
        if ('' !== $file['from']) {
            $under[] = Text::t('copied into the site from @source', ['@source' => $file['from']]);
            // A patch taken from a merge request is shared work, so the
            // re-roll belongs where the people who share it will get it.
            if (null !== MergeRequest::of($file['from'])) {
                $under[] = 'send your re-roll to that merge request and every site using it is fixed';
            }
        }
        // Only a run that left the choice to the service reaches this:
        // --from-source drops nothing, so the line says how to get the
        // files back.
        if ([] !== $file['dropped']) {
            $under[] = Text::t('@note; --from-source keeps them', ['@note' => self::droppedPathsNote(\count($file['dropped']))]);
        }
        if ([] !== $file['unioned']) {
            $under[] = Text::t('@note:', ['@note' => self::unionNote(\count($file['unioned']))]);
            $under[] = \array_map(static fn (array $region): string => Text::t('@file:@line', ['@file' => $region['file'], '@line' => $region['line']]), $file['unioned']);
        }

        return [Text::t('@path  (@status)', ['@path' => $file['path'], '@status' => self::fileStatus($file)]), $under];
    }

    /**
     * A written file's status: usable and whether the server verified it, or how many regions a person has to decide.
     *
     * @param WrittenRow $file
     */
    private static function fileStatus(array $file): string
    {
        if (PatchRow::CONFLICTS !== $file['status']) {
            // An unverified merge produced no conflict and nothing applied
            // it, so it is not yet a patch that works.
            return $file['verified'] ? 'verified against the release' : 'not verified';
        }
        // A file the release removed leaves no region anyone can decide, so
        // the file itself is the answer.
        if (0 === $file['regions'] && [] !== $file['removed']) {
            return Text::t('the release removed @files', ['@files' => \implode(', ', $file['removed'])]);
        }

        return Text::plural($file['regions'], '@count region to decide', '@count regions to decide');
    }

    /**
     * @param Change $change
     */
    private static function change(array $change): string
    {
        $values = ['@package' => $change['package'], '@title' => $change['title'], '@path' => $change['path']];
        if (Declarations::REPOINTED === $change['action']) {
            return Text::t('~ @package: @title → @path', $values);
        }

        return Text::t('' === $change['path'] ? '- @package: @title (already in the release)' : '- @package: @title (already in the release; @path is no longer used and was kept)', $values);
    }

    /**
     * One row the hook lists, over the lines that explain it.
     *
     * @return array{string, list<string>}
     */
    private static function hookRow(PatchRow $row): array
    {
        // An unclear row is the one case where the verdict alone says nothing.
        $under = '' === self::reason($row) ? [] : [self::reason($row)];
        foreach ($row->syntaxErrors as $error) {
            $under[] = Text::t('@mode: @error', ['@mode' => $row->failureMode(), '@error' => $error]);
        }
        // An applying row is listed for what it references, so that is its one line.
        if (PatchRow::APPLIES === $row->status()) {
            $rest = $row->flaggedCoreReferences() - 1;
            $first = self::coreReferenceLines($row)[0];
            $under[] = $rest > 0 ? Text::t('@first (+@rest more)', ['@first' => $first, '@rest' => $rest]) : $first;
        }
        $line = self::marked($row->status()).' '.self::pad($row->verdict, self::VERDICT_WIDTH).' '.Text::t('@package @version  @label', ['@package' => $row->package, '@version' => $row->version, '@label' => self::label($row)]);

        return [$line, $under];
    }

    /**
     * The hook's first line: what is left to decide, not what composer already applied.
     *
     * @param list<PatchRow> $rows
     */
    private static function hookHeadline(array $rows): string
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
        // The known statuses lead, worst first; a status this plugin does not know follows.
        $counts = \array_merge(\array_intersect_key(\array_fill_keys(self::MENTION_ORDER, 0), $counts), $counts);
        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = Text::t('@count @status', ['@count' => $count, '@status' => $status]);
        }
        if ($referencing > 0) {
            $parts[] = Text::t('@count with core references to check', ['@count' => $referencing]);
        }

        return Text::t('@tally after this update', ['@tally' => \implode(', ', $parts)]);
    }

    /**
     * The patches a lenient apply accepts and `git apply` refuses, each with what its re-roll comes to, and how many went unchecked.
     *
     * @param MoveReport                            $move
     * @param Closure(array<string, mixed>): string $at   one row's package and patch number, in the columns the run settled on
     *
     * @return list<string|list<string>>
     */
    private static function refusals(array $move, Closure $at): array
    {
        $refused = $move['refused'];
        if ([] === $refused) {
            $items = [0 === $move['unchecked'] ? 'the move stops no patch from applying' : 'the move stops no checked patch from applying'];
        } else {
            $items = [
                Text::plural(\count($refused), '@count patch needs a re-roll before 2.x applies it:', '@count patches need a re-roll before 2.x applies them:'),
                \array_map(static fn (array $row): string => Text::t('@at  @outcome', ['@at' => $at($row), '@outcome' => self::outcome($row)]), $refused),
            ];
            // A dry run leaves the real run to come, and it stops on a row that does not land.
            if (null === $move['wrote'] && [] !== \array_filter($refused, static fn (array $row): bool => !UpgradeCommand::lands($row))) {
                $items[] = self::STOPS;
            }
        }
        if ($move['unchecked'] > 0) {
            $items[] = Text::plural(
                $move['unchecked'],
                '@count patch was not checked, so this run cannot say whether 2.x applies it',
                '@count patches were not checked, so this run cannot say whether 2.x applies them'
            );
        }

        return $items;
    }

    /**
     * What one strict refusal's re-roll comes to, in the row's words.
     *
     * @param Refusal $row
     */
    private static function outcome(array $row): string
    {
        return match ($row['outcome']) {
            'clean' => 're-rolls clean',
            'open' => Text::plural($row['regions'], 'leaves @count region to decide', 'leaves @count regions to decide'),
            'none' => Text::t('no re-roll: @why', ['@why' => $row['why']]),
            'shipped' => 'already in the release',
        };
    }

    /**
     * What a real move did. A run that left regions open prints them in their own section, and wrote nothing else.
     *
     * @param Wrote $wrote
     *
     * @return list<string>
     */
    private static function wrote(array $wrote): array
    {
        $lines = [];
        if ($wrote['vendored'] > 0) {
            $lines[] = Text::plural($wrote['vendored'], '@count patch copied into this site', '@count patches copied into this site');
        }
        if ($wrote['rerolled'] > 0) {
            $lines[] = Text::plural($wrote['rerolled'], '@count patch re-rolled', '@count patches re-rolled');
        }
        foreach ($wrote['refused'] as $row) {
            $lines[] = self::tagged(Text::t('@title: @reason', ['@title' => $row['title'], '@reason' => $row['reason']]), 'comment');
        }
        if ($wrote['forcible'] > 0) {
            $lines[] = Text::plural(
                $wrote['forcible'],
                'run `@reroll --force` to replace the file this run would not overwrite',
                'run `@reroll --force` to replace the @count files this run would not overwrite',
                ['@reroll' => self::REROLL]
            );
        }
        if ([] === $wrote['open']) {
            $lines[] = [] === $wrote['files'] ? self::STAYED : Text::t('wrote @files', ['@files' => \implode(', ', $wrote['files'])]);
        }

        return $lines;
    }

    /**
     * The manager commands a real move finishes with, for a run that wrote nothing.
     *
     * @return list<string|list<string>>
     */
    private static function finishes(): array
    {
        return ['a real run finishes with:', \array_map(static fn (array $command): string => Text::t('composer @command', ['@command' => ManagerCommands::line($command)]), ManagerCommands::MOVE)];
    }

    /**
     * Why a run that copied a patch declared nothing.
     *
     * @param Outcome $result
     */
    private static function undeclared(array $result): string
    {
        if ('merged' === $result['verdict']) {
            return 'the release already has this change, so nothing was declared';
        }
        if ([] !== $result['regions']) {
            return Text::plural(
                \count($result['regions']),
                'decide @count region in @file, then run `@reroll`',
                'decide @count regions in @file, then run `@reroll`',
                ['@file' => $result['wrote'], '@reroll' => self::REROLL]
            );
        }

        return Text::t('nothing was declared: @reason', ['@reason' => $result['reason']]);
    }

    /**
     * One merge request on a line: its number, where it lands, when it was last pushed, and its title.
     *
     * @param Candidate $candidate
     */
    private static function candidate(array $candidate): string
    {
        return Text::t('!@iid  @target  @pushed  @title@draft', [
            '@iid' => self::pad($candidate['iid'], 5),
            '@target' => self::pad($candidate['target'], 8),
            // The stamp is ISO 8601, so the date is its first ten characters.
            '@pushed' => \substr($candidate['updated'], 0, 10),
            '@title' => $candidate['title'],
            '@draft' => $candidate['draft'] ? '  (draft)' : '',
        ]);
    }

    /**
     * The packages nothing was judged on, one line per reason, each said the way a package heading is said.
     *
     * @param Coverage             $coverage
     * @param array<string, mixed> $judged   the packages the table has rows for, as keys
     *
     * @return list<string>
     */
    private static function unjudged(array $coverage, array $judged): array
    {
        $out = [];
        foreach (self::grouped($coverage['skipped']) as $package => $reasons) {
            if (isset($judged[$package])) {
                continue;
            }
            // A site can declare a patch for a package it does not install,
            // and then there is no release to give.
            $heading = \trim($package.' '.($coverage['versions'][$package] ?? ''));
            foreach ($reasons as $reason => $count) {
                $out[] = Text::t('@heading   @skipped', ['@heading' => $heading, '@skipped' => self::skippedNote($count, (string) $reason)]);
            }
        }

        return $out;
    }

    /**
     * What one package's patches came to short of a verdict, one line per reason.
     *
     * @param Coverage $coverage
     *
     * @return list<string>
     */
    private static function coverageNotes(array $coverage, string $package): array
    {
        $out = [];
        foreach (self::grouped($coverage['skipped'])[$package] ?? [] as $reason => $count) {
            $out[] = self::skippedNote($count, (string) $reason);
        }
        foreach (self::grouped($coverage['unsent'])[$package] ?? [] as $reason => $count) {
            $out[] = Text::plural($count, '@count patch text not sent (@reason)', '@count patch texts not sent (@reason)', ['@reason' => $reason]);
        }

        return $out;
    }

    /**
     * What one reason kept from a verdict.
     */
    private static function skippedNote(int $count, string $reason): string
    {
        // A 2.x site's reason is composer patches-doctor's to give.
        if ('' === $reason) {
            return Text::plural($count, '@count patch skipped', '@count patches skipped');
        }

        return Text::plural($count, '@count patch skipped (@reason)', '@count patches skipped (@reason)', ['@reason' => $reason]);
    }

    /**
     * The entries counted per reason, under the package they are about, in the order they were declared.
     *
     * @param list<array{package: string, reason: string}> $entries
     *
     * @return array<string, array<string, int>>
     */
    private static function grouped(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[$entry['package']][$entry['reason']] = ($out[$entry['package']][$entry['reason']] ?? 0) + 1;
        }

        return $out;
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
            $parts[] = Text::plural($shipped, 'drops the shipped entry from composer.json', 'drops the @count shipped entries from composer.json');
        }

        return \implode(' and ', $parts);
    }

    /**
     * The patch file as a reader would say it: the last segment of its path or URL, without a query string.
     */
    private static function fileName(PatchRow $row): string
    {
        if ('' === $row->title || '' === $row->source) {
            return '';
        }
        $path = \explode('?', $row->source, 2)[0];
        $cut = \strrpos($path, '/');

        return self::fit(false === $cut ? $path : \substr($path, $cut + 1), self::TRAILING_MAX);
    }

    /**
     * What one package's patches came to, worst verdict first.
     *
     * @param array<int, PatchRow> $rows
     */
    private static function packageTally(array $rows): string
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status()] = ($counts[$row->status()] ?? 0) + 1;
        }
        \uksort($counts, static fn (string $a, string $b): int => [(self::STATUSES[$a] ?? self::UNRECOGNISED)[2], $a] <=> [(self::STATUSES[$b] ?? self::UNRECOGNISED)[2], $b]);

        return self::tally($counts);
    }

    /**
     * The package line: the release the verdicts are about, and the one the lock holds when they differ. The two spellings of a dev branch are one release.
     */
    private static function packageHeading(PatchRow $row): string
    {
        if ('' === $row->version || '' === $row->installed || self::branch($row->installed) === self::branch($row->version)) {
            return Text::t('@package @release', ['@package' => $row->package, '@release' => '' === $row->installed ? $row->version : $row->installed]);
        }

        return Text::t('@package @installed → @target', ['@package' => $row->package, '@installed' => $row->installed, '@target' => $row->version]);
    }

    private static function branch(string $version): string
    {
        return match (true) {
            \str_starts_with($version, 'dev-') => \substr($version, 4),
            \str_ends_with($version, '-dev') => \substr($version, 0, -4),
            default => $version,
        };
    }

    /**
     * What a write run changed and what it left, in the terms of the work still to do rather than a second tally to compare against the first.
     *
     * @param Write        $write
     * @param Rewrite|null $rewrite
     */
    private static function writeTally(Plan $plan, array $write, ?array $rewrite): string
    {
        $fixed = [];
        foreach ($write['written'] as $file) {
            if (PatchRow::CONFLICTS !== $file['status'] && $file['verified']) {
                $fixed[Declaration::keyOf($file['package'], $file['title'])] = true;
            }
        }
        $counts = [];
        foreach ($plan->rows() as $row) {
            $status = isset($fixed[$row->key()]) ? PatchRow::APPLIES : $row->status();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $parts = [];
        if ([] !== $fixed) {
            $parts[] = Text::plural(\count($fixed), '@count now applies', '@count now apply');
        }
        foreach ([PatchRow::CONFLICTS => '@count conflicts left', PatchRow::UNKNOWN => '@count unknown'] as $verdict => $template) {
            if (($counts[$verdict] ?? 0) > 0) {
                $parts[] = Text::t($template, ['@count' => $counts[$verdict]]);
            }
        }
        // A fix run has already dropped the entries it could, so what is
        // left to drop is what it did not touch.
        $dropped = \count(\array_filter($rewrite['changes'] ?? [], static fn (array $change): bool => Declarations::DROPPED === $change['action']));
        if (($counts[PatchRow::MERGED] ?? 0) - $dropped > 0) {
            $parts[] = Text::t('@count to drop', ['@count' => ($counts[PatchRow::MERGED] ?? 0) - $dropped]);
        }

        // A run that changed nothing and left nothing to do still gives the
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
        $counts = $plan->counts();
        foreach ($plan->rows() as $row) {
            if ('' !== $row->failureMode()) {
                $counts[$row->verdict] = ($counts[$row->verdict] ?? 0) - 1;
                $counts[$row->failureMode()] = ($counts[$row->failureMode()] ?? 0) + 1;
            }
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
     * The packages carrying at least one row of a status, in plan order and given once each.
     *
     * @return list<string>
     */
    private static function packagesWith(Plan $plan, string $status): array
    {
        $seen = [];
        foreach ($plan->rows() as $row) {
            if ($row->status() === $status) {
                $seen[$row->package] = true;
            }
        }

        return \array_keys($seen);
    }
}
