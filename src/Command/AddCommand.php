<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Fetch\IssueResolver;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\Ranking;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\Copied;
use TresBienTech\Drupatch\Write\Declarations;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Turns a drupal.org issue into a patch this site applies: copies the merge request's diff in, declares it, and calls the patch manager.
 *
 * @phpstan-import-type Candidate from Ranking
 *
 * @phpstan-type Chosen array{request: MergeRequest, issue: string, title: string, candidates: list<Candidate>, chosen: int}
 * @phpstan-type Outcome array{issue: string, title: string, package: string, path: string, verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int, candidates: list<Candidate>, chosen: int, error: string, dryRun: bool}
 */
class AddCommand extends PatchCommand
{
    use PicksMergeRequest;

    public const NAME = 'drupatch:add';

    /** What a site on 1.x is told, since the manager's own commands exist on 2.x alone. */
    public const NEEDS_TWO = 'this site runs cweagans/composer-patches 1.x, which has no '.ManagerCommands::RELOCK.'; run `composer '.Manager::UPGRADE.'` first';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Copy a merge request patch into this site, declare it, and apply it')
            ->addArgument('issue', InputArgument::REQUIRED, 'A drupal.org issue URL, its GitLab work-item form, or a merge request URL.')
            ->addOption('mr', null, InputOption::VALUE_REQUIRED, 'Take this merge request of the issue, by its number, without asking.')
            ->testFiles()
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would happen and write nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Write the declaration even where git reports this site\'s patches edited, and replace a copy git reports as changed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true === $input->getOption('dry-run');
        $wanted = $input->getOption('mr');
        try {
            $result = $this->add(
                (string) $input->getArgument('issue'),
                $dryRun,
                true === $input->getOption('force'),
                \is_string($wanted) ? \trim($wanted) : '',
                self::dropTests($input),
            );
        } catch (Throwable $e) {
            $result = self::stopped($e->getMessage());
        }

        // The verdict prints first, because the manager's own commands write
        // their progress straight to the terminal.
        $output->writeln(Report::print(Report::add($result)));
        if ('' !== $result['error'] || $dryRun || !$result['declared']) {
            return $result['exit'];
        }

        $applied = ManagerCommands::run(ManagerCommands::APPLY, $input->isInteractive(), $output);
        $output->writeln(Report::print(Report::unfinished($applied)));

        return '' === $applied['error'] ? $result['exit'] : Plan::ACTION_NEEDED;
    }

    /**
     * @return Outcome
     */
    private function add(string $reference, bool $dryRun, bool $force, string $wanted, ?bool $dropTests): array
    {
        $site = $this->site();
        $root = $site->root;
        // A pasted URL carries what the page put in the address bar: drupal.org's
        // #new anchor, GitLab's ?tab=. Neither names a different issue.
        $named = MergeRequest::of($url = (string) \strtok($reference, '#?'))
            ?? MergeRequest::of($url.'.diff')
            ?? IssueReference::of($url);
        if (null === $named) {
            return self::stopped(Text::t('@reference points at no drupal.org issue and no merge request on @host', ['@reference' => $reference, '@host' => MergeRequest::HOST]));
        }
        $manager = $site->manager;
        if (!$manager->isTwo()) {
            return self::stopped(self::NEEDS_TWO);
        }

        // The reference names its project, so a site that does not install it
        // is told before any host is asked anything.
        $package = IssueReference::packageOf($named->project);
        if ('' === $site->installs($package)) {
            return self::stopped(Text::t('this site does not install @package, so there is nothing to patch', ['@package' => $package]));
        }
        // What leaves the site is the checkable subset (ADR 0003). A fork or a
        // path repository keeps the drupal name and has no release behind it.
        if (!$site->canJudge($package)) {
            return self::stopped(Text::t('this site installs @package from outside drupal.org, so the service cannot judge a patch on it', ['@package' => $package]));
        }
        // Git is asked before any host or the service, so a refusal leaves
        // the site as it found it.
        $file = Declarations::documentFor($site->extra, $manager);
        $tree = $this->tree($force);
        $declarations = Declarations::checked($root, [$file], $dryRun ? null : $tree);

        $text = $site->text();
        $found = $this->choose($named, $wanted, new IssueResolver($text), $site->installs($package));
        if (\is_string($found)) {
            return self::stopped($found);
        }

        $title = Text::t('@issue: @title', ['@issue' => $found['issue'], '@title' => $found['title']]);
        // The declaration names the document the entry lands in. It opens a
        // package in the expanded shape, the one that holds its provenance.
        $copy = (new Vendoring($root, $text, $site->patchDirectory(), $manager, $tree))
            ->run([new Declaration($package, $title, $found['request']->url.'.diff', $file, true, [])], Scope::whole(), $dryRun);
        if ([] !== $copy->refused) {
            return self::stopped($copy->refused[0]['reason'], $found['issue'], $title, $package);
        }
        if ([] !== $copy->moved) {
            return self::stopped(Text::t('@path already holds this merge request at older commits; run `@pin --refresh` to take the new ones', ['@path' => $copy->moved[0]['path'], '@pin' => Report::PIN]), $found['issue'], $title, $package);
        }
        $copied = $copy->declarations[0];
        $plan = $this->judge($site, $text, $found['request'], $copied, $dropTests);
        if (\is_string($plan)) {
            return self::stopped($plan, $found['issue'], $title, $package);
        }
        $settled = $this->settle($plan, $root, $copy, $dryRun, $tree);
        if ($settled['declared'] && !$dryRun) {
            $this->declare($declarations, $copied, $settled['wrote']);
        }

        return [
            'issue' => $found['issue'],
            'title' => $title,
            'package' => $package,
            'path' => $copied->source,
            'candidates' => $found['candidates'],
            'chosen' => $found['chosen'],
            'error' => '',
            'dryRun' => $dryRun,
        ] + $settled;
    }

    /**
     * What the run does about the verdict: a patch the release already holds is dropped, one that fails is re-rolled, and a re-roll with regions left open is written for a person to finish.
     *
     * @param Copied $copy the one copy the run made or found
     *
     * @return array{verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int}
     */
    private function settle(Plan $plan, string $root, Copied $copy, bool $dryRun, ?WorkingTree $tree): array
    {
        // The plan is the service's answer, and an answer can hold no row.
        $row = $plan->rows()[0] ?? null;
        if (null === $row) {
            return self::settled('unknown', 'the service judged nothing', '', [], false, Plan::ACTION_NEEDED);
        }
        $exit = $plan->exitCode();
        if ($row->isMerged()) {
            // The release carries the change, so the copy is a file nobody
            // would apply.
            foreach ($dryRun ? [] : $copy->created() as $path) {
                @\unlink($root.\DIRECTORY_SEPARATOR.$path);
            }

            return self::settled($row->verdict, Report::reason($row), '', [], false, $exit);
        }
        // 2.x applies with git apply alone, so a strict refusal declared as
        // copied applies nothing, and no re-roll came to declare in its place.
        if (null === $row->reroll && PatchRow::REFUSED_BY_TWO === $row->failureMode()) {
            return self::settled($row->verdict, Text::t('@mode; @why', ['@mode' => $row->failureMode(), '@why' => PatchFiles::whyNoReroll($row)]), '', [], false, $exit);
        }
        if (null === $row->reroll || $dryRun) {
            return self::settled($row->verdict, Report::reason($row), $copy->declarations[0]->source, [], true, $exit);
        }

        return $this->reroll($plan, $root, $copy, $tree, $row, $exit);
    }

    /**
     * Writes the re-roll over the copy, or its conflict file beside it.
     *
     * A write lands on the path the copy's declaration names, which is the
     * copy itself; the request the bytes came from is no file to replace.
     *
     * @return array{verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int}
     */
    private function reroll(Plan $plan, string $root, Copied $copy, ?WorkingTree $tree, PatchRow $row, int $exit): array
    {
        $written = (new PatchFiles($root, $tree, $copy))->write($plan);
        $file = $written['written'][0] ?? null;
        if (null === $file) {
            // A row the run asked a re-roll for lands in one list or the other.
            $refused = $written['refused'][0];
            $reason = '' === $refused['lifts']
                ? $refused['reason']
                : Text::t('@path: @why; pass @flag', ['@path' => $refused['path'], '@why' => $refused['reason'], '@flag' => $refused['lifts']]);

            return self::settled($row->verdict, $reason, '', [], false, $exit);
        }
        $open = \array_map(
            static fn (array $region): string => Text::t('@file region @region', ['@file' => $region['file'], '@region' => $region['region']]),
            $file['open'],
        );

        // A patch config never names a conflict file: it holds regions
        // nobody has decided.
        return self::settled($row->verdict, Report::reason($row), $file['path'], $open, 'clean' === $file['status'], $exit);
    }

    /**
     * @param list<string> $regions the regions a person still has to decide
     *
     * @return array{verdict: string, reason: string, wrote: string, regions: list<string>, declared: bool, exit: int}
     */
    private static function settled(string $verdict, string $reason, string $wrote, array $regions, bool $declared, int $exit): array
    {
        return ['verdict' => $verdict, 'reason' => $reason, 'wrote' => $wrote, 'regions' => $regions, 'declared' => $declared, 'exit' => $exit];
    }

    /**
     * The merge request this run acts on, and the issue behind it.
     *
     * A merge request URL names one already. `--mr` names one of an issue's
     * requests without a search, because a run that cannot ask still has to
     * reach one. An issue on its own is searched, and the run picks.
     *
     * @return Chosen|string
     */
    private function choose(IssueReference|MergeRequest $named, string $wanted, IssueResolver $resolver, string $version): array|string
    {
        if ($named instanceof MergeRequest) {
            return '' !== $wanted && $wanted !== $named->iid
                ? Text::t('the argument gives merge request @iid, and --mr gives @wanted', ['@iid' => $named->iid, '@wanted' => $wanted])
                : $this->confirm($named, $resolver);
        }
        if ('' !== $wanted) {
            $found = $this->named($named, $wanted, $resolver);

            return \is_string($found) ? $found : ['request' => $found['request'], 'issue' => $named->number, 'title' => $found['title'], 'candidates' => [], 'chosen' => 0];
        }
        $found = $this->picked($named, $resolver, $version);
        if (\is_string($found)) {
            return $found;
        }
        ['ordered' => $ordered, 'at' => $at] = $found;

        return ['request' => $found['request'], 'issue' => $named->number, 'title' => $ordered[$at]['title'], 'candidates' => $ordered, 'chosen' => $at];
    }

    /**
     * The issue behind a merge request the argument names.
     *
     * @return Chosen|string
     */
    private function confirm(MergeRequest $request, IssueResolver $resolver): array|string
    {
        $behind = $resolver->behind($request);
        if (\is_string($behind)) {
            return $behind;
        }

        return ['request' => $request, 'issue' => $behind['issue'], 'title' => $behind['title'], 'candidates' => [], 'chosen' => 0];
    }

    /**
     * What the service says about the copied patch against the release this site installs.
     */
    private function judge(Site $site, PatchText $text, MergeRequest $request, Declaration $copied, ?bool $dropTests): Plan|string
    {
        $record = $copied->provenance;
        // A copy the run lifted from an old header may name no commits, and
        // the compare endpoint then answers with something that is no diff.
        $compare = $request->compare($record['base'] ?? '', $record['head'] ?? '');
        $read = $text->read($compare);
        if ('' !== $read['reason']) {
            return $read['reason'];
        }

        // A re-roll is asked for, so a patch that no longer fits is offered
        // as one rather than reported and left. The request carries the
        // package's own patches, so this one is judged behind them. The run
        // reads its own patch alone, under the site's patch manager.
        return Client::fromComposer($this->requireComposer(), $this->getIO())
            ->plan($site->requestFor($copied, $read['files'][$compare] ?? ''), $site->manager, new Scope([], [$copied->source]), reroll: true, dropTests: $dropTests);
    }

    /**
     * Writes the declaration where this site already keeps its patches.
     *
     * @param string $wrote the file the declaration names, which is the re-roll when the run wrote one
     */
    private function declare(Declarations $declarations, Declaration $copied, string $wrote): void
    {
        $declarations->write([['action' => Declarations::ADDED, 'package' => $copied->package, 'title' => $copied->title, 'path' => $wrote, 'provenance' => $copied->provenance]], [$copied]);
    }

    /**
     * @return Outcome
     */
    private static function stopped(string $error, string $issue = '', string $title = '', string $package = ''): array
    {
        return ['issue' => $issue, 'title' => $title, 'package' => $package, 'path' => '', 'verdict' => '', 'reason' => '', 'wrote' => '', 'regions' => [], 'declared' => false, 'exit' => Plan::ACTION_NEEDED, 'candidates' => [], 'chosen' => 0, 'error' => $error, 'dryRun' => false];
    }
}
