<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Command;

use Composer\Command\BaseCommand;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Run;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Text;
use TresBienTech\Drupatch\Write\WorkingTree;
use UnexpectedValueException;

/**
 * What the commands share: the options that pick a target, a scope and a shape, the rewrite of the site's declarations, and the report.
 *
 * @phpstan-import-type Write from Report
 * @phpstan-import-type Rewrite from Report
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
     * The flag every command that re-rolls takes, on the files the release package leaves out.
     */
    protected function fromSource(): static
    {
        return $this->addOption(
            'from-source',
            null,
            InputOption::VALUE_NONE,
            'Keep every hunk. Pass it when the site is installed from source, so it holds the files a release package leaves out.'
        );
    }

    /**
     * What the run asks a re-roll to do about the files a release package leaves out: false keeps every hunk, null leaves it to the service.
     */
    protected static function dropChoice(InputInterface $input): ?bool
    {
        return true === $input->getOption('from-source') ? false : null;
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
            throw new UnexpectedValueException(Text::t('unknown --format=@named; accepted: @accepted', ['@named' => $format, '@accepted' => \implode(', ', self::FORMATS)]));
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
            $output->writeln(Report::message($e->getMessage(), 'error'));

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
     * The site this run is about, read once.
     */
    protected function site(): Site
    {
        return Site::read($this->requireComposer(), $this->getIO());
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
        $out = '' === $target ? [] : [Text::t('--target @core', ['@core' => $target])];
        foreach ($scope->packages as $package) {
            $out[] = Text::t('--package @package', ['@package' => $package]);
        }
        foreach ($scope->sources as $source) {
            $out[] = Text::t('--patch @source', ['@source' => $source]);
        }

        return $out;
    }

    /**
     * The working tree a run asks git through, built once per run; none under `--force`, which asks nothing.
     */
    protected function tree(bool $force): ?WorkingTree
    {
        return $force ? null : new WorkingTree(new ProcessExecutor($this->getIO()));
    }

    /**
     * Prints the plan in the shape asked for, with what the run wrote beside it.
     *
     * @param Write|null   $write   what a re-roll run wrote
     * @param Rewrite|null $rewrite the declaration rewrite a re-roll run made
     */
    protected function render(InputInterface $input, OutputInterface $output, string $format, Run $run, Plan $plan, ?array $write, ?array $rewrite): void
    {
        if ('json' === $format) {
            $output->writeln((string) \json_encode(Report::json($plan, $write, $rewrite), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            self::notes($output, true)->writeln(Report::print(Report::unpinnedWarning($plan->manager, \count(Report::unpinned($plan)))));

            return;
        }
        $width = Report::clamp((new Terminal())->getWidth());
        $output->writeln(Report::print(Report::check($plan, $run->coverage, $write, $rewrite, $width, self::repeated($run->target, self::scope($input)))));
    }
}
