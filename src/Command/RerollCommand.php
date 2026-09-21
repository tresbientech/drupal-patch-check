<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Command;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Fetch\IssueResolver;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Request;
use TresBienTech\Drupatch\Read\Run;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Source\UrlPatch;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\Copied;
use TresBienTech\Drupatch\Write\Decisions;
use TresBienTech\Drupatch\Write\Declarations;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Re-rolls the patches that no longer apply and writes the result: the merged diff where it is clean, a conflict file where it is not. Every run reads the conflict files it finds and sends the regions decided in them.
 */
class RerollCommand extends PatchCommand
{
    use PicksMergeRequest;

    public const NAME = 'drupatch:reroll';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription("Re-roll this site's patches that no longer apply, and write what merges")
            ->shared()
            ->fromSource()
            ->addOption('decisions', null, InputOption::VALUE_REQUIRED, 'A JSON document of decided regions, or - for stdin: {"decisions": [{"source", "file", "region", "choice": "release"|"patch" or "text"}]}. Merged with the conflict files; the document wins on a region both decide.')
            ->addOption('mr', null, InputOption::VALUE_REQUIRED, 'The merge request number to take for every declaration this run resolves to an issue. For a run nobody can answer; a run that can asks per issue.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a patch file git reports as changed or untracked, and rewrite a declaration file with uncommitted changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $target = \is_string($target) ? \trim($target) : '';
        $force = true === $input->getOption('force');
        $dryRun = true === $input->getOption('dry-run');
        $printing = self::printing($input, $output);
        if (null === $printing) {
            return Plan::FAILED;
        }
        [$format, $notes] = $printing;

        try {
            $dropTests = self::dropChoice($input);
            $run = new Run($this->site(), $this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input), $dropTests);
            $patches = $run->request->patches->patches;
            $decided = Decisions::onDisk($run->site->root, $patches, self::scope($input));
            $fromDocument = [];
            $document = $input->getOption('decisions');
            if (\is_string($document) && '' !== $document) {
                $fromDocument = Decisions::fromDocument(self::documentText($input, $document), $patches, self::scope($input));
                $merged = Decisions::merge($decided, $fromDocument);
                $decided = $merged['decided'];
                foreach ($merged['overridden'] as $region) {
                    $notes->writeln(Report::message(Text::t('the document decides @file region @region of @source, over its conflict file', ['@file' => $region['file'], '@region' => $region['region'], '@source' => $patches[$region['patch']]->source]), 'comment'));
                }
            }
            if ($dryRun) {
                $output->writeln((string) \json_encode($run->body(true, $decided), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                return Plan::CLEAN;
            }
            $tree = $this->tree($force);
            // Git is asked once, before the service: a refusal then costs no
            // re-roll, and every declaration rewrite below is this run's own.
            $declarations = Declarations::checked($run->site->root, Declarations::documentsIn($patches, self::scope($input)), $tree);
            $plan = $run->plan(true, $decided);
            self::refuseStaleDecisions($plan, $patches, $fromDocument, $decided);
            // A conflicting patch whose title names an issue is resolved to
            // that issue's merge request first, so the merge below reads
            // upstream's current diff rather than the copy the site kept.
            $pinned = $this->pinResolved($run, $plan, self::wantedRequest($input), $tree, $notes);
            $repointed = [];
            if ([] !== $pinned->changes()) {
                $repointed = $declarations->write($pinned->changes(), $run->request->patches->patches);
                $run = new Run($this->site(), $this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input), $dropTests);
                $plan = $run->plan(true, $decided);
            }
            // The copies this run pinned are its own to replace.
            $result = (new PatchFiles($run->site->root, $tree, $pinned->over($run->request->patches->patches)))->write($plan);
        } catch (Throwable $e) {
            $notes->writeln(Report::message($e->getMessage(), 'error'));

            return Plan::FAILED;
        }

        // The rewrite runs before anything prints, so what it did sits under
        // the rows; a rewrite that fails still gets the report printed first.
        $rewrite = null;
        $updateError = '';
        try {
            [$changes, $rewritten] = $this->update($declarations, $run->request, $plan, $result['written']);
            $rewrite = ['changes' => \array_merge($pinned->changes(), $changes), 'declaration' => self::documents(\array_merge($repointed, $rewritten))];
        } catch (Throwable $e) {
            $updateError = $e->getMessage();
        }

        $this->render($input, $output, $format, $run, $plan, $result, $rewrite);

        if ('' !== $updateError) {
            $notes->writeln(Report::message($updateError, 'error'));

            return Plan::FAILED;
        }

        return $plan->exitCode();
    }

    /**
     * `--mr` as the run typed it, empty when it named none.
     */
    private static function wantedRequest(InputInterface $input): string
    {
        $wanted = $input->getOption('mr');

        return \is_string($wanted) ? \trim($wanted) : '';
    }

    /**
     * Copies in every merge request this run resolved. The caller writes the
     * repoints, so one place records what the run rewrote.
     *
     * @return Copied what the run copied in, over the declarations it resolved
     */
    private function pinResolved(Run $run, Plan $plan, string $wanted, ?WorkingTree $tree, OutputInterface $notes): Copied
    {
        $site = $run->site;
        // 1.x reads the compact declaration and keeps the source alone, so a
        // copy made here would carry no base and the merge would gain
        // nothing by it.
        if (!$site->manager->isTwo()) {
            return Copied::none([]);
        }
        $root = $site->root;
        $text = $site->text();
        $found = $this->resolveTitles($plan, $run->request->patches->patches, new IssueResolver($text), $wanted);
        foreach ($found['refused'] as $refusal) {
            $notes->writeln(Report::message(Text::t('@title stays as declared: @reason', ['@title' => $refusal['title'], '@reason' => $refusal['reason']]), 'comment'));
        }
        if ([] === $found['declarations']) {
            return Copied::none([]);
        }
        $copy = (new Vendoring($root, $text, $site->patchDirectory(), $site->manager, $tree))
            ->run($found['declarations'], Scope::whole(), false);
        foreach ($copy->refused as $row) {
            $notes->writeln(Report::message(Text::t('@title stays as declared: @reason', ['@title' => $row['title'], '@reason' => $row['reason']]), 'comment'));
        }

        return $copy;
    }

    /**
     * The declarations this run resolves to a merge request before it merges.
     *
     * A conflicting patch the site declared as a local file, holding no
     * patch provenance and titled with a drupal.org issue
     * number, belongs to that issue. Its merge requests are ranked against
     * the installed release the same way `drupatch:add` ranks them, and the
     * chosen one becomes the declaration's new source. The copy itself is
     * `Fetch\Vendoring`'s, so a resolved patch lands where every other
     * vendored patch lands.
     *
     * @param list<Declaration> $declared
     *
     * @return array{declarations: list<Declaration>, refused: list<array{title: string, reason: string}>}
     */
    private function resolveTitles(Plan $plan, array $declared, IssueResolver $resolver, string $wanted): array
    {
        $declarations = [];
        $refused = [];
        foreach ($plan->rows() as $row) {
            if (!$row->conflicts()) {
                continue;
            }
            $held = Declaration::find($declared, $row->key());
            if (null === $held || [] !== $held->provenance || UrlPatch::isUrl($held->source)) {
                continue;
            }
            $issue = IssueReference::inDeclaration($row->project, $row->title, $held->source);
            if (null === $issue) {
                continue;
            }
            $request = $this->requestFor($issue, $resolver, $wanted, $row->installed);
            if (\is_string($request)) {
                $refused[] = ['title' => $row->title, 'reason' => $request];
                continue;
            }
            $declarations[] = $held->at($request->url.'.diff', []);
        }

        return ['declarations' => $declarations, 'refused' => $refused];
    }

    /**
     * The merge request one issue's declaration takes, or why it takes none.
     *
     * `--mr` names one for every issue a run resolves, because a run nobody
     * can answer still has to reach one. A run that can answer is asked per
     * issue, with the best non-draft offered.
     */
    private function requestFor(IssueReference $issue, IssueResolver $resolver, string $wanted, string $version): MergeRequest|string
    {
        $found = '' === $wanted ? $this->picked($issue, $resolver, $version) : $this->named($issue, $wanted, $resolver);

        return \is_string($found) ? $found : $found['request'];
    }

    /**
     * The decisions document as text: a file the site can read, or stdin for `-`.
     */
    private static function documentText(InputInterface $input, string $path): string
    {
        if ('-' === $path) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $text = \stream_get_contents($stream ?? \STDIN);
        } else {
            $text = @\file_get_contents($path);
        }
        if (false === $text) {
            throw new RuntimeException($path.' is not readable');
        }

        return $text;
    }

    /**
     * A decision the service found no conflicted region for decides nothing, and the service does not say so: it counts what it applied. A patch the document decided is refused when fewer were applied than sent, before anything is written.
     *
     * @param list<Declaration>                      $patches
     * @param array<int, list<array<string, mixed>>> $fromDocument
     * @param array<int, list<array<string, mixed>>> $sent
     */
    private static function refuseStaleDecisions(Plan $plan, array $patches, array $fromDocument, array $sent): void
    {
        if ([] === $fromDocument) {
            return;
        }
        $rows = [];
        foreach ($plan->rows() as $row) {
            $rows[$row->key()] = $row;
        }
        foreach (\array_keys($fromDocument) as $i) {
            $patch = $patches[$i];
            $row = $rows[$patch->key()] ?? throw new RuntimeException(Text::t('the plan has no row for @source', ['@source' => $patch->source]));
            $applied = $row->reroll->resolutionsApplied ?? 0;
            $count = \count($sent[$i] ?? []);
            if ($count > $applied) {
                throw new RuntimeException(Text::t('@undecided of the @sent decisions sent for @source named no conflicted region, so they decided nothing; nothing was written', ['@undecided' => $count - $applied, '@sent' => $count, '@source' => $patch->source]));
            }
        }
    }

    /**
     * Rewrites the site's declarations, and returns what changed with the files it wrote.
     *
     * @param list<array{path: string, provenance: array<string, string>, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return array{0: list<array{action: 'dropped'|'repointed', package: string, title: string, path: string, provenance: array<string, string>}>, 1: list<string>}
     */
    private function update(Declarations $declarations, Request $request, Plan $plan, array $written): array
    {
        $changes = Declarations::changes($plan, $written);
        if ([] === $changes) {
            return [[], []];
        }

        return [$changes, $declarations->write($changes, $request->patches->patches)];
    }

    /**
     * The documents a run rewrote, named once each; a run that rewrote none names composer.json, which is where the report says nothing changed.
     *
     * @param list<string> $written
     */
    private static function documents(array $written): string
    {
        $documents = \array_values(\array_unique($written));

        return [] === $documents ? Declaration::COMPOSER_JSON : \implode(', ', $documents);
    }
}
