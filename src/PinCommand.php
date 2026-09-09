<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\PinReport;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * Copies every patch this site declares from a merge request into the site, so what composer applies stops changing when somebody pushes.
 */
class PinCommand extends PatchCommand
{
    public const NAME = 'drupatch:pin';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Copy every patch declared from a merge request into this site')
            ->scoped()
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Take the new commits of a merge request that moved since this site copied it.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a file git reports as changed, and rewrite a declaration file git reports as changed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true === $input->getOption('dry-run');
        $printing = self::printing($input, $output);
        if (null === $printing) {
            return Plan::FAILED;
        }
        [$format, $notes] = $printing;

        try {
            $composer = $this->requireComposer();
            $root = Site::rootDirectory();
            $extra = $composer->getPackage()->getExtra();
            $declared = PatchConfig::declared($extra);
            $force = true === $input->getOption('force');
            $tree = $force ? null : new WorkingTree(new ProcessExecutor($this->getIO()));
            $result = (new Vendoring($root, PatchText::fromComposer($composer, $this->getIO(), $root), Plugin::patchDirectory($extra), $tree))
                ->run($declared, self::scope($input), $dryRun, true === $input->getOption('refresh'));
        } catch (Throwable $e) {
            $notes->writeln('<error>'.Text::t('drupatch: @message', ['@message' => $e->getMessage()]).'</error>');

            return Plan::FAILED;
        }

        $changes = [];
        foreach ([...$result['vendored'], ...$result['kept'], ...$result['moved']] as $row) {
            if ($row['source'] !== $row['path']) {
                $changes[] = ['action' => 'repointed', 'package' => $row['package'], 'title' => $row['title'], 'path' => $row['path']];
            }
        }
        $rewriteError = '';
        if (!$dryRun && [] !== $changes) {
            try {
                $this->rewriteDeclarations($root, $changes, $force);
            } catch (Throwable $e) {
                $rewriteError = $e->getMessage();
            }
        }

        $this->print($output, $format, $result, '' === $rewriteError ? $changes : [], self::DECLARATION, self::unpinned($result, !$dryRun && '' === $rewriteError));
        if ('' !== $rewriteError) {
            $notes->writeln('<error>'.Text::t('drupatch: @message', ['@message' => $rewriteError]).'</error>');

            return Plan::FAILED;
        }

        return [] === $result['refused'] ? Plan::CLEAN : Plan::ACTION_NEEDED;
    }

    /**
     * How many declarations still name a merge request now the run is over: what it could not copy, and every one of them when it rewrote nothing.
     *
     * @param array{vendored: list<array<string, string>>, kept: list<array<string, string>>, moved: list<array<string, string>>, refused: list<array<string, string>>} $result
     * @param bool                                                                                                                                                      $repointed whether the run wrote the declarations it copied a file for
     */
    private static function unpinned(array $result, bool $repointed): int
    {
        $rows = $repointed ? $result['refused'] : [...$result['vendored'], ...$result['kept'], ...$result['moved'], ...$result['refused']];

        return \count(MergeRequest::among($rows));
    }

    /**
     * @param array{vendored: list<array<string, string>>, kept: list<array<string, string>>, moved: list<array<string, string>>, refused: list<array<string, string>>} $result
     * @param list<array{action: string, package: string, title: string, path: string}>                                                                                 $changes
     */
    private function print(OutputInterface $output, string $format, array $result, array $changes, string $declaration, int $unpinned): void
    {
        if ('table' !== $format) {
            $output->writeln((string) \json_encode($result + ['rewritten' => \count($changes)], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            $notes = self::notes($output, true);
            foreach (Report::unpinnedWarning($unpinned) as $line) {
                $notes->writeln($line);
            }

            return;
        }
        foreach (PinReport::lines($result, $declaration, \count($changes), $unpinned) as $line) {
            $output->writeln($line);
        }
    }
}
