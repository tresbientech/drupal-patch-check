<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Throwable;
use Tresbien\Drupatch\Outcome;
use Tresbien\Drupatch\PatchConfig\Lines;
use Tresbien\Drupatch\Plan\Client;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Plan\Value;
use Tresbien\Drupatch\Render\Annotations;
use Tresbien\Drupatch\Render\Budget;
use Tresbien\Drupatch\Render\Coverage;
use Tresbien\Drupatch\Render\Format;
use Tresbien\Drupatch\Render\Summary;
use Tresbien\Drupatch\Render\Table;
use Tresbien\Drupatch\Resolve\Candidates;
use Tresbien\Drupatch\Resolve\Declared;
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
    /** The word a run uses to ask for the newest core its constraint allows. */
    private const TARGET_LATEST = 'latest';

    protected function configure(): void
    {
        $this
            ->setName('drupal-patch-check')
            // The package is drupatch, so that spelling is what a person
            // guesses from the install line. Both resolve.
            ->setAliases(['drupatch-check'])
            ->setDescription("Check this site's composer patches against the releases it installs")
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Core version to plan against, e.g. 11.4.5, or `latest` for the newest core your own constraint allows. Without it the installed releases are checked.')
            ->addOption('reroll', null, InputOption::VALUE_NONE, 'Write a re-rolled patch file for every patch that no longer applies')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Rewrite the patch declarations: drop what shipped upstream, point the rest at their re-rolls. Implies --reroll.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Let --fix rewrite a file that already has uncommitted changes')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this package, repeatable: drupal/webform or webform. Narrows the report, --reroll and --fix, and the exit code with them.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on a patch that could not be judged and on a package with no release, as well as on one that will not apply')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as one JSON object. The same as --format=json.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output shape: '.\implode(', ', Format::accepted()).'. Defaults to table.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the request that would be sent and stop. Nothing is asked of the service and nothing is written.');
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
        [$path] = self::declaration($site);
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

    /**
     * The plan the run is about: the whole site, or the packages --package
     * named.
     *
     * @throws RuntimeException when a named package declares no patch
     */
    /**
     * The packages a run was scoped to, empty for the whole site.
     *
     * @return list<string>
     */
    private static function scope(InputInterface $input): array
    {
        $option = $input->getOption('package');
        $packages = [];
        foreach (\is_array($option) ? $option : [] as $name) {
            if (\is_string($name) && '' !== \trim($name)) {
                $packages[] = \trim($name);
            }
        }

        return $packages;
    }

    private function narrow(Plan $plan, InputInterface $input): Plan
    {
        $packages = self::scope($input);
        if ([] === $packages) {
            return $plan;
        }
        $declared = $plan->packages();
        $narrowed = $plan->onlyPackages($packages);
        if (!$narrowed->hasPatches()) {
            throw new RuntimeException(\sprintf('no patch is declared for %s; this site declares patches for %s', \implode(', ', $packages), [] === $declared ? 'nothing' : \implode(', ', $declared)));
        }

        return $narrowed;
    }

    /**
     * What composer would install for each patched package, or nothing
     * when it cannot say. A site with no repository in reach still gets
     * the server's answer, and hears why it got no candidates.
     *
     * @return array<string, string>
     */
    private function candidates(Composer $composer, Site $site, string &$target, OutputInterface $output): array
    {
        $resolver = null;
        try {
            $resolver = Candidates::forSite($composer);
            $out = $this->resolveCandidates($resolver, $site, $target);
        } catch (Throwable $e) {
            $output->writeln('<comment>drupatch: composer resolved no candidates, '.$e->getMessage().'</comment>');
            $out = [];
        }
        if (null !== $resolver) {
            foreach ($resolver->notes() as $note) {
                $output->writeln('<comment>drupatch: '.$note.'</comment>');
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function resolveCandidates(Candidates $resolver, Site $site, string &$target): array
    {
        // `latest` is a question. Composer answers questions about a
        // release, so it is resolved to one here: otherwise every
        // constraint is compared against the word and refuses, and the run
        // silently falls back to the service's own release table.
        if (self::TARGET_LATEST === $target) {
            $resolved = $resolver->coreTarget($site->constraints());
            if ('' === $resolved) {
                // The site requires no core package. The service falls
                // back to the installed core and says so; nothing here can
                // pick candidates for a core nobody named.
                return [];
            }
            $target = $resolved;
        }
        $wanted = [];
        $constraints = $site->constraints();
        foreach ($site->patches()->patches as $patch) {
            $package = $patch['package'];
            $wanted[$package] = $constraints[$package] ?? '';
        }
        if ([] === $wanted) {
            return [];
        }

        return $resolver->forTarget($target, $wanted);
    }

    /**
     * The document declaring the site's patches, as a path relative to
     * the site root and as text. An unreadable file reads as empty, and
     * every annotation then anchors to its first line.
     *
     * @return array{string, string}
     */
    private static function declaration(Site $site): array
    {
        $file = $site->patches()->file;
        $path = '' === $file ? 'composer.json' : $file;
        $text = @\file_get_contents($site->root().\DIRECTORY_SEPARATOR.$path);

        return [$path, false === $text ? '' : $text];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $target = \is_string($target) ? \trim($target) : '';
        $fix = true === $input->getOption('fix');
        $reroll = $fix || true === $input->getOption('reroll');
        // Resolved before anything is read or asked for, so a run told
        // two different things stops without touching the site.
        $chosen = $input->getOption('format');
        try {
            $format = Format::of(\is_string($chosen) ? $chosen : null, true === $input->getOption('json'));
        } catch (Throwable $e) {
            $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Outcome::FAILED;
        }

        try {
            $composer = $this->requireComposer();
            $site = Site::atWorkingDirectory($composer);
            foreach ($site->patches()->notes as $note) {
                $output->writeln('<comment>drupatch: '.$note.'</comment>');
            }
            foreach ($site->patches()->unsent as $line) {
                $output->writeln('<comment>drupatch: patch text not sent, '.$line.'</comment>');
            }
            $coverage = Coverage::of($site, self::scope($input));
            foreach ($coverage->lines() as $line) {
                $output->writeln('<comment>'.$line.'</comment>');
            }
            // A bare run judges what the lock installs, so there is no
            // candidate to resolve and no repository to ask.
            $candidates = '' === $target ? [] : $this->candidates($composer, $site, $target, $output);
            // What the site has on disk says what it supports, whatever
            // the service's copy of the release data knows.
            $declared = Declared::forSite($composer, $site->checkable());
            if (true === $input->getOption('dry-run')) {
                $output->writeln((string) \json_encode(
                    Client::body($site->composerJson(), $site->composerLock(), $site->patches(), $target, $reroll, $candidates, $declared),
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
                ));

                return Outcome::CLEAN;
            }
            $plan = Client::fromComposer($composer, $this->getIO())
                ->plan($site->composerJson(), $site->composerLock(), $site->patches(), $target, $reroll, $candidates, $declared);
            // The whole site is sent because the server needs the whole
            // lock; the narrowing happens here, so everything after it
            // is about the packages that were asked for.
            $plan = $this->narrow($plan, $input);
            $written = $reroll ? PatchFiles::forPlan($site->root(), $plan)->write($plan) : [];
        } catch (Throwable $e) {
            $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Outcome::FAILED;
        }

        $strict = true === $input->getOption('strict');
        if (Format::JSON === $format) {
            $output->writeln((string) \json_encode($plan->raw + [
                'summary' => Summary::of($plan, $strict, $coverage->isVacuous()),
            ] + ['written' => \array_map(
                static fn (WrittenFile $file): array => ['path' => $file->path, 'status' => $file->status],
                $written
            )], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } elseif (Format::GITHUB === $format) {
            [$path, $text] = self::declaration($site);
            foreach (Annotations::lines($plan, $path, Lines::in($text)) as $line) {
                $output->writeln($line);
            }
        } else {
            foreach (Table::report($plan, $written, Budget::clamp((new Terminal())->getWidth())) as $line) {
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

        return Outcome::of($plan, $strict, $coverage->isVacuous());
    }
}
