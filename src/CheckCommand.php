<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * Checks this site's patches, against the releases it installs or against the ones a target core would bring in. Writes nothing.
 */
class CheckCommand extends PatchCommand
{
    public const NAME = 'drupatch:check';

    /** The name the command shipped under before the namespace; still accepted. */
    public const ALIAS = 'drupal-patch-check';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setAliases([self::ALIAS])
            ->setDescription("Check this site's composer patches against the releases it installs")
            ->shared();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $target = \is_string($target) ? \trim($target) : '';
        $dryRun = true === $input->getOption('dry-run');
        // Resolved before anything is read or asked for, so a run asking
        // for an unknown shape stops without touching the site.
        $chosen = $input->getOption('format');
        try {
            $format = self::format(\is_string($chosen) ? $chosen : null, true === $input->getOption('json'));
        } catch (Throwable $e) {
            $output->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Plan::FAILED;
        }
        $notes = self::notes($output, 'table' !== $format || $dryRun);

        try {
            $run = new Run($this->requireComposer(), $this->getIO(), $notes, $target, self::scope($input));
            if ($dryRun) {
                $output->writeln((string) \json_encode($run->body(false, []), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                return Plan::CLEAN;
            }
            $plan = $run->plan(false, []);
        } catch (Throwable $e) {
            $notes->writeln('<error>drupatch: '.$e->getMessage().'</error>');

            return Plan::FAILED;
        }

        $this->render($input, $output, $format, $run, $plan, null);

        return $plan->exitCode(true === $input->getOption('strict'), $run->coverage->isVacuous());
    }
}
