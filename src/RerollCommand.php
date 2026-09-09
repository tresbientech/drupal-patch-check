<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Write\ConfigRewriter;
use TresBienTech\Drupatch\Write\Decisions;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Re-rolls the patches that no longer apply and writes the result: the merged diff where it is clean, a conflict file where it is not. Every run reads the conflict files it finds and sends the regions decided in them.
 */
class RerollCommand extends PatchCommand
{
    public const NAME = 'drupatch:reroll';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription("Re-roll this site's patches that no longer apply, and write what merges")
            ->shared()
            ->addOption('decisions', null, InputOption::VALUE_REQUIRED, 'A JSON document of decided regions, or - for stdin: {"decisions": [{"source", "file", "region", "choice": "release"|"patch" or "text"}]}. Merged with the conflict files; the document wins on a region both decide.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a patch file git reports as changed or untracked, and rewrite a declaration file with uncommitted changes');
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
            $run = new Run($this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input));
            $patches = $run->site->patches()->patches;
            $decided = Decisions::onDisk($run->site->root(), $patches, self::scope($input));
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
            $plan = $run->plan(true, $decided);
            self::refuseStaleDecisions($plan, $patches, $fromDocument, $decided);
            $tree = $force ? null : new WorkingTree(new ProcessExecutor($this->getIO()));
            $result = (new PatchFiles($run->site->root(), $tree, $run->site->patches()->patches))->write($plan);
        } catch (Throwable $e) {
            $notes->writeln('<error>'.Text::t('drupatch: @message', ['@message' => $e->getMessage()]).'</error>');

            return Plan::FAILED;
        }

        // The rewrite runs before anything prints, so what it did sits under
        // the rows; a rewrite that fails still gets the report printed first.
        $outcomes = Outcomes::fromWrite($result);
        $updateError = '';
        try {
            $outcomes->recordFix($this->update($run->site, $plan, $result['written'], $force), self::DECLARATION);
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
     * Rewrites the site's declarations and returns what changed, empty when nothing did.
     *
     * @param list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>
     */
    private function update(Site $site, Plan $plan, array $written, bool $force): array
    {
        $changes = ConfigRewriter::changes($plan, $written);
        if ([] === $changes) {
            return [];
        }
        $this->rewriteDeclarations($site->root(), $changes, $force);

        return $changes;
    }
}
