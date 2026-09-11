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
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Read\PatchConfig;
use TresBienTech\Drupatch\Read\Run;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Source\IssueReference;
use TresBienTech\Drupatch\Source\MergeRequest;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\ConfigRewriter;
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
            ->testFiles()
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
            $dropTests = self::dropTests($input);
            $run = new Run($this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input), $dropTests);
            $patches = $run->site->patches->patches;
            $decided = Decisions::onDisk($run->site->root, $patches, self::scope($input));
            $fromDocument = [];
            $document = $input->getOption('decisions');
            if (\is_string($document) && '' !== $document) {
                $fromDocument = Decisions::fromDocument(self::documentText($input, $document), $patches, self::scope($input));
                $merged = Decisions::merge($decided, $fromDocument);
                $decided = $merged['decided'];
                foreach ($merged['overridden'] as $region) {
                    $notes->writeln('<comment>'.Text::t('drupatch: the document decides @file region @region of @source, over its conflict file', ['@file' => $region['file'], '@region' => $region['region'], '@source' => $patches[$region['patch']]['source']]).'</comment>');
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
            $pinned = $this->pinResolved($run, $plan, self::wantedRequest($input), $tree, $declarations, $notes);
            if ([] !== $pinned->changes()) {
                $run = new Run($this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input), $dropTests);
                $plan = $run->plan(true, $decided);
            }
            // The copies this run pinned are its own to replace.
            $result = (new PatchFiles($run->site->root, $tree, $pinned->over($run->site->patches->patches)))->write($plan);
        } catch (Throwable $e) {
            $notes->writeln('<error>'.Text::t('drupatch: @message', ['@message' => $e->getMessage()]).'</error>');

            return Plan::FAILED;
        }

        // The rewrite runs before anything prints, so what it did sits under
        // the rows; a rewrite that fails still gets the report printed first.
        $outcomes = Outcomes::fromWrite($result);
        $updateError = '';
        try {
            [$changes, $rewritten] = $this->update($declarations, $run->site, $plan, $result['written']);
            $outcomes->recordFix($changes, $rewritten);
        } catch (Throwable $e) {
            $updateError = $e->getMessage();
        }

        $this->render($input, $output, $format, $run, $plan, $outcomes);

        if ('' !== $updateError) {
            $notes->writeln('<error>'.Text::t('drupatch: @message', ['@message' => $updateError]).'</error>');

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
     * Copies in every merge request this run resolved, and repoints the
     * declarations at what it copied.
     *
     * @return Copied what the run copied in, over the declarations it resolved
     */
    private function pinResolved(Run $run, Plan $plan, string $wanted, ?WorkingTree $tree, Declarations $declarations, OutputInterface $notes): Copied
    {
        $composer = $this->requireComposer();
        $manager = Manager::fromComposer($composer);
        // 1.x reads the compact declaration and keeps the source alone, so a
        // copy made here would carry no base and the merge would gain
        // nothing by it.
        if (!$manager->isTwo()) {
            return Copied::none([]);
        }
        $root = $run->site->root;
        $declared = $run->site->patches->patches;
        $text = $this->patchText($root);
        $found = $this->resolveTitles($plan, $declared, new IssueResolver($text), $wanted);
        foreach ($found['refused'] as $refusal) {
            $notes->writeln('<comment>'.Text::t('drupatch: @title stays as declared: @reason', ['@title' => $refusal['title'], '@reason' => $refusal['reason']]).'</comment>');
        }
        if ([] === $found['declarations']) {
            return Copied::none([]);
        }
        $extra = $composer->getPackage()->getExtra();
        $copy = (new Vendoring($root, $text, Plugin::patchDirectory($extra), $manager, $tree))
            ->run($found['declarations'], Scope::whole(), false);
        foreach ($copy->refused as $row) {
            $notes->writeln('<comment>'.Text::t('drupatch: @title stays as declared: @reason', ['@title' => $row['title'], '@reason' => $row['reason']]).'</comment>');
        }
        if ([] !== $copy->changes()) {
            $declarations->write($copy->changes(), $declared);
        }

        return $copy;
    }

    /**
     * The declarations this run resolves to a merge request before it merges.
     *
     * A conflicting patch the site declared as a local file, holding no
     * record of where its bytes came from and titled with a drupal.org issue
     * number, belongs to that issue. Its merge requests are ranked against
     * the installed release the same way `drupatch:add` ranks them, and the
     * chosen one becomes the declaration's new source. The copy itself is
     * `Fetch\Vendoring`'s, so a resolved patch lands where every other
     * vendored patch lands.
     *
     * @param list<array{package: string, title: string, source: string, file: string, shape: string, provenance: array<string, string>}> $declared
     *
     * @return array{declarations: list<array{package: string, title: string, source: string, provenance: array<string, string>}>, refused: list<array{title: string, reason: string}>}
     */
    private function resolveTitles(Plan $plan, array $declared, IssueResolver $resolver, string $wanted): array
    {
        $declarations = [];
        $refused = [];
        foreach ($plan->patches as $row) {
            if (!$row->conflicts()) {
                continue;
            }
            $held = $row->declaredIn($declared);
            if (null === $held || [] !== $held['provenance'] || PatchConfig::isUrl($held['source'])) {
                continue;
            }
            $issue = IssueReference::inDeclaration($row->project, $row->title, $held['source']);
            if (null === $issue) {
                continue;
            }
            $request = $this->requestFor($issue, $resolver, $wanted, $row->installed);
            if (\is_string($request)) {
                $refused[] = ['title' => $row->title, 'reason' => $request];
                continue;
            }
            $declarations[] = ['package' => $row->package, 'title' => $row->title, 'source' => $request->url.'.diff', 'provenance' => []];
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
     * @param list<array{package: string, title: string, source: string}> $patches
     * @param array<int, list<array<string, mixed>>>                      $fromDocument
     * @param array<int, list<array<string, mixed>>>                      $sent
     */
    private static function refuseStaleDecisions(Plan $plan, array $patches, array $fromDocument, array $sent): void
    {
        if ([] === $fromDocument) {
            return;
        }
        $rows = [];
        foreach ($plan->patches as $row) {
            $rows[$row->key()] = $row;
        }
        foreach (\array_keys($fromDocument) as $i) {
            $patch = $patches[$i];
            $row = $rows[PatchRow::keyOf($patch['package'], $patch['title'])] ?? throw new RuntimeException(Text::t('the plan has no row for @source', ['@source' => $patch['source']]));
            $applied = (int) ($row->reroll['resolutions_applied'] ?? 0);
            $count = \count($sent[$i] ?? []);
            if ($count > $applied) {
                throw new RuntimeException(Text::t('@undecided of the @sent decisions sent for @source named no conflicted region, so they decided nothing; nothing was written', ['@undecided' => $count - $applied, '@sent' => $count, '@source' => $patch['source']]));
            }
        }
    }

    /**
     * Rewrites the site's declarations, and returns what changed with the files it wrote.
     *
     * @param list<array{path: string, provenance: array<string, string>, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return array{0: list<array{action: 'dropped'|'repointed', package: string, title: string, path: string, provenance: array<string, string>}>, 1: string}
     */
    private function update(Declarations $declarations, Site $site, Plan $plan, array $written): array
    {
        $changes = ConfigRewriter::changes($plan, $written);
        if ([] === $changes) {
            return [[], PatchConfig::COMPOSER_JSON];
        }
        $rewritten = $declarations->write($changes, $site->patches->patches);

        return [$changes, \implode(', ', $rewritten)];
    }
}
