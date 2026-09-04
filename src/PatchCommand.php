<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Render\Report;
use UnexpectedValueException;

/**
 * What the check and the re-roll share: the options that pick a target, a scope and a shape, and the report they both end with.
 */
abstract class PatchCommand extends BaseCommand
{
    /** Output shapes, in the order the help text lists them. */
    private const FORMATS = ['table', 'json', 'github'];

    /** The options one command carried before the split, and what does each job now. */
    private const REMOVED = [
        '--write' => Report::REROLL,
        '--resolve' => Report::REROLL.', which reads the conflict files on every run',
        '--fix' => Report::REROLL.' --update',
    ];

    /**
     * The options both commands take.
     */
    protected function shared(): static
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Core version to plan against, e.g. 11.4.5, or `latest` for the newest core your own constraint allows. Without it the installed releases are checked.')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this package, repeatable: drupal/webform or webform. Narrows the report, what is written, and the exit code.')
            ->addOption('patch', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this patch, named by the source the site declares, a path or a URL. Repeatable, and combines with --package.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Also fail on a patch that could not be judged, and on a run that declared patches and judged none')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as one JSON object. The same as --format=json.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output shape: '.\implode(', ', self::FORMATS).'. Defaults to table.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the request that would be sent and stop. Nothing is asked of the service and nothing is written.');

        return $this;
    }

    /**
     * A run passing an option the split removed is told what replaced it, before the console's own refusal.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            foreach (self::REMOVED as $flag => $now) {
                if (\str_contains($e->getMessage(), '"'.$flag.'"')) {
                    $output->writeln('<error>drupatch: '.$flag.' is gone; run '.$now.'</error>');

                    return Plan::FAILED;
                }
            }
            throw $e;
        }
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
        $out = '' === $target ? [] : ['--target '.$target];
        foreach ($scope->packages as $package) {
            $out[] = '--package '.$package;
        }
        foreach ($scope->sources as $source) {
            $out[] = '--patch '.$source;
        }

        return $out;
    }

    /**
     * The document declaring the site's patches, as a path relative to the site root and as text.
     *
     * @return array{string, string}
     */
    protected static function declaration(Site $site): array
    {
        $file = $site->patches()->file;
        $path = '' === $file ? 'composer.json' : $file;
        $text = @\file_get_contents($site->root().\DIRECTORY_SEPARATOR.$path);

        return [$path, false === $text ? '' : $text];
    }

    /**
     * Prints the plan in the shape asked for, with what the run wrote beside it.
     */
    protected function render(InputInterface $input, OutputInterface $output, string $format, Run $run, Plan $plan, ?Outcomes $outcomes): void
    {
        $strict = true === $input->getOption('strict');
        if ('json' === $format) {
            $raw = null === $outcomes ? $plan->raw : $outcomes->intoDocument($plan->raw);
            $output->writeln((string) \json_encode($raw + [
                'summary' => Report::summary($plan, $strict, $run->coverage->isVacuous(), $outcomes),
            ] + ['written' => \array_map(
                static fn (array $file): array => ['path' => $file['path'], 'status' => $file['status']],
                null === $outcomes ? [] : $outcomes->written()
            )] + ['refused' => \array_map(
                static fn (array $refusal): array => ['path' => $refusal['path'], 'reason' => $refusal['reason']],
                null === $outcomes ? [] : $outcomes->refused()
            )], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } elseif ('github' === $format) {
            [$path, $text] = self::declaration($run->site);
            foreach (Report::annotations($plan, $path, $text) as $line) {
                $output->writeln($line);
            }
        } else {
            $scope = self::repeated($run->target, self::scope($input));
            foreach (Report::report($plan, $run->coverage, $outcomes, Report::clamp((new Terminal())->getWidth()), $scope) as $line) {
                $output->writeln($line);
            }
        }
    }
}
