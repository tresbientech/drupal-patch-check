<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Throwable;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Coverage;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Write\ConfigRewriter;
use TresBienTech\Drupatch\Write\Decisions;
use TresBienTech\Drupatch\Write\PatchFiles;
use TresBienTech\Drupatch\Write\WorkingTree;
use UnexpectedValueException;

/**
 * Checks this site's patches, against the releases it installs or against the ones a target core would bring in.
 */
class CheckCommand extends BaseCommand
{
    /** The word a run uses to ask for the newest core its constraint allows. */
    private const TARGET_LATEST = 'latest';

    /** Output shapes, in the order the help text lists them. */
    private const FORMATS = ['table', 'json', 'github'];

    /** What `--resolve` says when no conflict file has been worked through. */
    public const NOTHING_DECIDED = 'no conflict file has a decided region; nothing to resolve and nothing written';

    protected function configure(): void
    {
        $this
            ->setName('drupal-patch-check')
            // The package is drupatch, so that spelling is what a person
            // guesses from the install line. Both resolve.
            ->setAliases(['drupatch-check'])
            ->setDescription("Check this site's composer patches against the releases it installs")
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Core version to plan against, e.g. 11.4.5, or `latest` for the newest core your own constraint allows. Without it the installed releases are checked.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Replace every patch file whose patch no longer applies with its re-roll, and write a .conflict.patch beside the ones that did not merge')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Rewrite the patch declarations: drop what shipped upstream, adopt the ones declared as URLs. Implies --write.')
            ->addOption('resolve', null, InputOption::VALUE_NONE, 'Re-read the .conflict.patch files, send the regions you decided, and write back what the service verifies. Implies --write.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Let --fix rewrite a file that already has uncommitted changes')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this package, repeatable: drupal/webform or webform. Narrows the report, --write and --fix, and the exit code with them.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on a patch that could not be judged and on a package with no release, as well as on one that will not apply')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as one JSON object. The same as --format=json.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output shape: '.\implode(', ', self::FORMATS).'. Defaults to table.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the request that would be sent and stop. Nothing is asked of the service and nothing is written.');
    }

    /**
     * The shape a run prints in; --format wins over the --json flag.
     */
    public static function format(?string $format, bool $json): string
    {
        if (null === $format) {
            return $json ? 'json' : 'table';
        }
        if (!\in_array($format, self::FORMATS, true)) {
            throw new UnexpectedValueException(\sprintf('unknown --format=%s; accepted: %s', $format, \implode(', ', self::FORMATS)));
        }

        return $format;
    }

    /**
     * Rewrites the site's declarations and returns what changed, empty when nothing did.
     *
     * @param list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>
     */
    private function fix(Site $site, Plan $plan, array $written, bool $force): array
    {
        $changes = ConfigRewriter::changes($plan, $written);
        if ([] === $changes) {
            return [];
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

        if ('' === $file) {
            $declared = (array) ($decoded['extra']['patches'] ?? []);
            $updated = ConfigRewriter::intoComposerJson($text, ConfigRewriter::apply($declared, $changes));
        } else {
            $declared = \is_array($decoded['patches'] ?? null) ? $decoded['patches'] : $decoded;
            $updated = ConfigRewriter::intoPatchesFile($text, ConfigRewriter::apply($declared, $changes));
        }
        if (false === \file_put_contents($full, $updated)) {
            throw new RuntimeException($path.' could not be written');
        }

        return $changes;
    }

    /**
     * The packages a run was scoped to, empty for the whole site.
     *
     * @return list<string>
     */
    private static function scope(InputInterface $input): array
    {
        $packages = [];
        foreach ((array) $input->getOption('package') as $name) {
            if (\is_string($name) && '' !== \trim($name)) {
                $packages[] = \trim($name);
            }
        }

        return $packages;
    }

    /**
     * The options a next run repeats, so the suggested command acts on what this run showed.
     *
     * @param list<string> $packages
     *
     * @return list<string>
     */
    public static function repeated(string $target, array $packages): array
    {
        $out = '' === $target ? [] : ['--target '.$target];
        foreach ($packages as $package) {
            $out[] = '--package '.$package;
        }

        return $out;
    }

    /**
     * The plan narrowed to the packages --package named.
     *
     * @throws RuntimeException when a named package declares no patch
     */
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
     * What composer would install for each patched package, or nothing when it cannot say.
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
     * The document declaring the site's patches, as a path relative to the site root and as text.
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
        $resolve = true === $input->getOption('resolve');
        $reroll = true === $input->getOption('write') || $fix || $resolve;
        // Resolved before anything is read or asked for, so a run asking
        // for an unknown shape stops without touching the site.
        $chosen = $input->getOption('format');
        try {
            $format = self::format(\is_string($chosen) ? $chosen : null, true === $input->getOption('json'));
        } catch (Throwable $e) {
            $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Plan::FAILED;
        }
        // The json and github shapes are read by machines, so every line
        // meant for a person goes to stderr and stdout stays parseable.
        $notes = 'table' !== $format && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $composer = $this->requireComposer();
            $site = Site::atWorkingDirectory($composer);
            foreach ($site->patches()->notes as $note) {
                $notes->writeln('<comment>drupatch: '.$note.'</comment>');
            }
            foreach ($site->patches()->unsent as $line) {
                $notes->writeln('<comment>drupatch: patch text not sent, '.$line.'</comment>');
            }
            $coverage = Coverage::of($site, self::scope($input));
            foreach ($coverage->lines() as $line) {
                $notes->writeln('<comment>'.$line.'</comment>');
            }
            // A bare run judges what the lock installs, so there is no
            // candidate to resolve and no repository to ask.
            $candidates = '' === $target ? [] : $this->candidates($composer, $site, $target, $notes);
            // What the site has on disk says what it supports, whatever
            // the service's copy of the release data knows.
            $declared = Candidates::declaredCore($composer, $site->checkable());
            $decided = $resolve
                ? Decisions::onDisk($site->root(), $site->patches()->patches, self::scope($input))
                : [];
            if ($resolve && [] === $decided) {
                $notes->writeln('<comment>drupatch: '.self::NOTHING_DECIDED.'</comment>');

                return Plan::CLEAN;
            }
            if (true === $input->getOption('dry-run')) {
                $output->writeln((string) \json_encode(
                    Client::body($site->composerJson(), $site->composerLock(), $site->patches(), $target, $reroll, $candidates, $declared, $decided),
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
                ));

                return Plan::CLEAN;
            }
            $plan = Client::fromComposer($composer, $this->getIO())
                ->plan($site->composerJson(), $site->composerLock(), $site->patches(), $target, $reroll, $candidates, $declared, $decided);
            // The whole site is sent because the server needs the whole
            // lock; the narrowing happens here, so everything after it
            // is about the packages that were asked for.
            $plan = $this->narrow($plan, $input);
            $tree = true === $input->getOption('force') ? null : new WorkingTree(new ProcessExecutor($this->getIO()));
            $result = $reroll
                ? (new PatchFiles($site->root(), $tree, $site->patches()->patches, $fix))->write($plan)
                : ['written' => [], 'refused' => []];
            $written = $result['written'];
        } catch (Throwable $e) {
            $notes->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Plan::FAILED;
        }

        // The rewrite runs before anything prints, so what it did sits under
        // the rows; a rewrite that fails still gets the report printed first.
        $outcomes = null;
        $fixError = '';
        if ($reroll) {
            $outcomes = Outcomes::fromWrite($result);
            if ($fix) {
                try {
                    $outcomes->recordFix($this->fix($site, $plan, $written, true === $input->getOption('force')), self::declaration($site)[0]);
                } catch (Throwable $e) {
                    $fixError = $e->getMessage();
                }
            }
        }

        $strict = true === $input->getOption('strict');
        if ('json' === $format) {
            $output->writeln((string) \json_encode($plan->raw + [
                'summary' => Report::summary($plan, $strict, $coverage->isVacuous(), $outcomes),
            ] + ['written' => \array_map(
                static fn (array $file): array => ['path' => $file['path'], 'status' => $file['status']],
                $written
            )] + ['refused' => \array_map(
                static fn (array $refusal): array => ['path' => $refusal['path'], 'reason' => $refusal['reason']],
                $result['refused']
            )], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } elseif ('github' === $format) {
            [$path, $text] = self::declaration($site);
            foreach (Report::annotations($plan, $path, $text) as $line) {
                $output->writeln($line);
            }
        } else {
            $scope = self::repeated($target, self::scope($input));
            foreach (Report::report($plan, $outcomes, Report::clamp((new Terminal())->getWidth()), $scope) as $line) {
                $output->writeln($line);
            }
        }

        if ('' !== $fixError) {
            $notes->writeln('<error>drupatch: '.$fixError.'</error>');

            return Plan::FAILED;
        }

        return $plan->exitCode($strict, $coverage->isVacuous());
    }
}
