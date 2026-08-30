<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Command;

use Composer\Command\BaseCommand;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Tresbien\Drupatch\Outcome;
use Tresbien\Drupatch\Plan\Client;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Plan\Value;
use Tresbien\Drupatch\Render\Table;
use Tresbien\Drupatch\Site;
use Tresbien\Drupatch\Write\ConfigRewriter;
use Tresbien\Drupatch\Write\PatchFiles;
use Tresbien\Drupatch\Write\WorkingTree;
use Tresbien\Drupatch\Write\WrittenFile;

/**
 * Checks this site's patches, against the releases it installs or against
 * the ones a target core would bring in.
 *
 * The command reads the site and writes nothing.
 */
final class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('drupal-patch-check')
            // The package is drupatch, so that spelling is what a person
            // guesses from the install line. Both resolve.
            ->setAliases(['drupatch-check'])
            ->setDescription("Check this site's composer patches against the releases it installs")
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Core version to plan against, e.g. 11.4.5. Without it the installed releases are checked.')
            ->addOption('reroll', null, InputOption::VALUE_NONE, 'Write a re-rolled patch file for every patch that no longer applies')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Rewrite the patch declarations: drop what shipped upstream, point the rest at their re-rolls. Implies --reroll.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Let --fix rewrite a file that already has uncommitted changes')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as one JSON object');
    }

    /**
     * Rewrites the site's declarations and says what changed.
     *
     * @param list<WrittenFile> $written
     *
     * @return list<string>
     */
    private function fix(Site $site, Plan $plan, array $written, bool $force): array
    {
        $changes = ConfigRewriter::changes($plan, $written);
        if ([] === $changes) {
            return ['', '  nothing to change: no patch shipped upstream and none was re-rolled cleanly'];
        }

        $file = $site->patches()->file;
        $path = '' === $file ? 'composer.json' : $file;
        if (!$force && (new WorkingTree(new ProcessExecutor($this->getIO())))->isModified($site->root(), $path)) {
            throw new RuntimeException($path.' has uncommitted changes; commit them or pass --force');
        }

        $full = $site->root().\DIRECTORY_SEPARATOR.$path;
        $text = @\file_get_contents($full);
        if (false === $text) {
            throw new RuntimeException($path.' is not readable');
        }
        $decoded = \json_decode($text, true);
        if (!\is_array($decoded)) {
            throw new RuntimeException($path.' is not readable JSON');
        }
        $decoded = Value::keyed($decoded);

        if ('' === $file) {
            $declared = Value::object(Value::object($decoded, 'extra'), 'patches');
            $updated = ConfigRewriter::intoComposerJson($text, ConfigRewriter::apply($declared, $changes));
        } else {
            $declared = isset($decoded['patches']) ? Value::object($decoded, 'patches') : $decoded;
            $updated = ConfigRewriter::intoPatchesFile($text, ConfigRewriter::apply($declared, $changes));
        }
        if (false === \file_put_contents($full, $updated)) {
            throw new RuntimeException($path.' could not be written');
        }

        $lines = ['', '  '.$path.':'];
        foreach ($changes as $change) {
            $lines[] = $change->line();
        }

        return $lines;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $target = \is_string($target) ? \trim($target) : '';
        $fix = true === $input->getOption('fix');
        $reroll = $fix || true === $input->getOption('reroll');

        try {
            $composer = $this->requireComposer();
            $site = Site::atWorkingDirectory($composer);
            foreach ($site->patches()->notes as $note) {
                $output->writeln('<comment>drupatch: '.$note.'</comment>');
            }
            $plan = Client::fromComposer($composer, $this->getIO())
                ->plan($site->composerJson(), $site->composerLock(), $site->patches(), $target, $reroll);
            $written = $reroll ? PatchFiles::forPlan($site->root(), $plan)->write($plan) : [];
        } catch (Throwable $e) {
            $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Outcome::FAILED;
        }

        if (true === $input->getOption('json')) {
            $output->writeln((string) \json_encode($plan->raw + ['written' => \array_map(
                static fn (WrittenFile $file): array => ['path' => $file->path, 'status' => $file->status],
                $written
            )], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } else {
            foreach (Table::lines($plan) as $line) {
                $output->writeln($line);
            }
            foreach (Table::written($written) as $line) {
                $output->writeln($line);
            }
        }

        if ($fix) {
            try {
                foreach ($this->fix($site, $plan, $written, true === $input->getOption('force')) as $line) {
                    $output->writeln($line);
                }
            } catch (Throwable $e) {
                $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

                return Outcome::FAILED;
            }
        }

        return Outcome::of($plan);
    }
}
