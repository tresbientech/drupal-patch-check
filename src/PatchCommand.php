<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Command\BaseCommand;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Write\ConfigRewriter;
use TresBienTech\Drupatch\Write\WorkingTree;
use UnexpectedValueException;

/**
 * What the commands share: the options that pick a target, a scope and a shape, the rewrite of the site's declarations, and the report.
 */
abstract class PatchCommand extends BaseCommand
{
    /** Output shapes, in the order the help text lists them. */
    private const FORMATS = ['table', 'json'];

    /**
     * The options both commands take.
     */
    protected function shared(): static
    {
        return $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Core version to plan against, e.g. 11.4.5, or `latest` for the newest core your own constraint allows. Without it the installed releases are checked.')
            ->scoped();
    }

    /**
     * The options every command takes: what to act on, and what to print.
     */
    protected function scoped(): static
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this package, repeatable: drupal/webform or webform. Narrows the report, what is written, and the exit code.')
            ->addOption('patch', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this patch, named by the source the site declares, a path or a URL. Repeatable, and combines with --package.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output shape: '.\implode(', ', self::FORMATS).'. Defaults to table.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the request that would be sent and stop. Nothing is asked of the service and nothing is written.');

        return $this;
    }

    /**
     * The shape a run prints in, the table when nothing was asked for.
     */
    public static function format(?string $format): string
    {
        if (null === $format) {
            return 'table';
        }
        if (!\in_array($format, self::FORMATS, true)) {
            throw new UnexpectedValueException(Text::t('unknown --format=@named; accepted: @accepted', ['named' => $format, 'accepted' => \implode(', ', self::FORMATS)]));
        }

        return $format;
    }

    /**
     * How a run prints: the shape it was asked for, and where its notes go. Resolved before the site is read, so a run asking for an unknown shape stops without touching it.
     *
     * @return array{string, OutputInterface}|null null when the shape was refused, with the reason already printed
     */
    protected static function printing(InputInterface $input, OutputInterface $output): ?array
    {
        $chosen = $input->getOption('format');
        try {
            $format = self::format(\is_string($chosen) ? $chosen : null);
        } catch (UnexpectedValueException $e) {
            $output->writeln('<error>'.Text::t('drupatch: @message', ['message' => $e->getMessage()]).'</error>');

            return null;
        }

        return [$format, self::notes($output, 'table' !== $format || true === $input->getOption('dry-run'))];
    }

    /**
     * Where lines meant for a person go: stderr when stdout carries a document, so what a pipe reads stays parseable.
     */
    protected static function notes(OutputInterface $output, bool $parseable): OutputInterface
    {
        return $parseable && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * What the run was scoped to, the whole site when nothing was named.
     */
    protected static function scope(InputInterface $input): Scope
    {
        return new Scope(self::named($input, 'package'), self::named($input, 'patch'));
    }

    /**
     * @return list<string>
     */
    private static function named(InputInterface $input, string $option): array
    {
        $out = [];
        foreach ((array) $input->getOption($option) as $name) {
            if (\is_string($name) && '' !== \trim($name)) {
                $out[] = \trim($name);
            }
        }

        return $out;
    }

    /**
     * The options a next run repeats, so the suggested command acts on what this run showed.
     *
     * @return list<string>
     */
    public static function repeated(string $target, Scope $scope): array
    {
        $out = '' === $target ? [] : [Text::t('--target @core', ['core' => $target])];
        foreach ($scope->packages as $package) {
            $out[] = Text::t('--package @package', ['package' => $package]);
        }
        foreach ($scope->sources as $source) {
            $out[] = Text::t('--patch @source', ['source' => $source]);
        }

        return $out;
    }

    /** The document a site declares its patches in. */
    public const DECLARATION = 'composer.json';

    /**
     * Points the site's declarations at what a run wrote, refusing when git reports the patches in composer.json edited since the last commit.
     *
     * @param list<array{action: string, package: string, title: string, path: string}> $changes
     *
     * @throws RuntimeException when the file cannot be read or its patches have uncommitted changes
     */
    protected function rewriteDeclarations(string $root, array $changes, bool $force): void
    {
        $full = $root.\DIRECTORY_SEPARATOR.self::DECLARATION;
        $text = @\file_get_contents($full);
        if (false === $text) {
            throw new RuntimeException(Text::t('@file is not readable', ['file' => self::DECLARATION]));
        }
        $declared = self::patchesOf($text);
        if (null === $declared) {
            throw new RuntimeException(Text::t('@file is not readable JSON', ['file' => self::DECLARATION]));
        }
        if (!$force) {
            $tree = new WorkingTree(new ProcessExecutor($this->getIO()));
            if ($tree->isModified($root, self::DECLARATION)) {
                // A run reaches the rewrite with the rest of the file already
                // edited: the constraints it bumped to reach the new core.
                // Comparing the whole file would refuse nearly every real run.
                $committed = $tree->committed($root, self::DECLARATION);
                if (null === $committed || $declared !== self::patchesOf($committed)) {
                    throw new RuntimeException(Text::t('@file has uncommitted changes to its patches; commit them or pass --force', ['file' => self::DECLARATION]));
                }
            }
        }
        $updated = ConfigRewriter::intoComposerJson($text, ConfigRewriter::apply($declared, $changes));
        if (false === \file_put_contents($full, $updated)) {
            throw new RuntimeException(Text::t('@file could not be written', ['file' => self::DECLARATION]));
        }
    }

    /**
     * The patch declarations composer.json holds, null when it is not readable JSON.
     *
     * @return array<string, mixed>|null
     */
    protected static function patchesOf(string $text): ?array
    {
        $decoded = \json_decode($text, true);

        return \is_array($decoded) ? (array) ($decoded['extra']['patches'] ?? []) : null;
    }

    /**
     * Prints the plan in the shape asked for, with what the run wrote beside it.
     */
    protected function render(InputInterface $input, OutputInterface $output, string $format, Run $run, Plan $plan, ?Outcomes $outcomes): void
    {
        if ('json' === $format) {
            $raw = null === $outcomes ? $plan->raw : $outcomes->intoDocument($plan->raw);
            $output->writeln((string) \json_encode($raw + [
                'summary' => Report::summary($plan, $outcomes),
            ] + ['written' => \array_map(
                static fn (array $file): array => ['path' => $file['path'], 'status' => $file['status']],
                null === $outcomes ? [] : $outcomes->written()
            )] + ['refused' => \array_map(
                static fn (array $refusal): array => ['path' => $refusal['path'], 'reason' => $refusal['reason']],
                null === $outcomes ? [] : $outcomes->refused()
            )], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } else {
            $scope = self::repeated($run->target, self::scope($input));
            foreach (Report::report($plan, $run->coverage, $outcomes, Report::clamp((new Terminal())->getWidth()), $scope) as $line) {
                $output->writeln($line);
            }
        }
    }
}
