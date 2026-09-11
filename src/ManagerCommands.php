<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch;

use Composer\Util\Platform;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The patch manager's own commands, which are the only thing that applies a patch.
 *
 * drupatch writes the declarations and runs these; it applies nothing itself.
 * Each runs as a child of the composer that started this run, the way
 * composer runs its own `@composer` scripts, so the manager that answers is
 * the one installed when the child starts.
 *
 * @phpstan-type Ran array{ran: list<string>, error: string, left: list<string>}
 */
class ManagerCommands
{
    /** Rewrites the patch lock from the declarations. */
    public const RELOCK = 'patches-relock';

    /** Reinstalls the packages, so the lock's patches reach the code. */
    public const REPATCH = 'patches-repatch';

    /** What applies the declarations: the lock written from them, then applied. */
    public const APPLY = [[self::RELOCK], [self::REPATCH]];

    /**
     * What finishes a **manager upgrade**: the install that brings 2.x in, moving the manager and its own dependencies alone, then the lock written and applied.
     */
    public const MOVE = [['update', Manager::PACKAGE, '--with-dependencies'], [self::RELOCK], [self::REPATCH]];

    /**
     * Runs each command in order, and stops at the first that fails.
     *
     * @param non-empty-list<list<string>> $commands    each command's words after `composer`
     * @param bool                         $interactive whether a person is there to answer the child's prompts
     *
     * @return Ran the commands that finished, why the run stopped, and the commands a person still runs
     */
    public static function run(array $commands, bool $interactive, OutputInterface $output): array
    {
        $composer = self::composer();
        if (\is_string($composer)) {
            $why = Text::t('@why, so this run cannot start composer @command', ['@why' => $composer, '@command' => self::line($commands[0])]);

            return ['ran' => [], 'error' => $why, 'left' => \array_map(self::line(...), $commands)];
        }
        $console = self::console($interactive, $output);
        $ran = [];
        foreach ($commands as $at => $command) {
            $line = self::line($command);
            $output->writeln('  '.Text::t('-> composer @command', ['@command' => $line]));
            $error = self::one([...$composer, ...$command, ...$console], $line, $interactive, $output);
            if ('' !== $error) {
                return ['ran' => $ran, 'error' => $error, 'left' => \array_map(self::line(...), \array_slice($commands, $at))];
            }
            $ran[] = $line;
        }

        return ['ran' => $ran, 'error' => '', 'left' => []];
    }

    /**
     * Why a run stopped and what a person runs to finish; nothing for a run that finished.
     *
     * @param Ran $ran
     *
     * @return list<string>
     */
    public static function stopped(array $ran): array
    {
        if ('' === $ran['error']) {
            return [];
        }
        $left = \array_map(static fn (string $command): string => '`composer '.$command.'`', $ran['left']);

        return ['  <error>'.$ran['error'].'</error>', '  '.Text::t('run @commands to finish', ['@commands' => \implode(' then ', $left)])];
    }

    /**
     * One command as a person types it after `composer`.
     *
     * @param list<string> $command
     */
    public static function line(array $command): string
    {
        return \implode(' ', $command);
    }

    /**
     * The words that start this composer again: the PHP running it, the three settings composer carries into its own children, and its binary.
     *
     * @return list<string>|string the words, or why there are none
     */
    private static function composer(): array|string
    {
        $binary = Platform::getEnv('COMPOSER_BINARY');
        if (false === $binary || '' === $binary) {
            return 'COMPOSER_BINARY is unset';
        }
        $finder = new PhpExecutableFinder();
        $php = $finder->find(false);
        if (false === $php) {
            return 'no PHP binary was found';
        }

        return [
            $php,
            ...$finder->findArguments(),
            '-d', 'allow_url_fopen='.(string) \ini_get('allow_url_fopen'),
            '-d', 'disable_functions='.(string) \ini_get('disable_functions'),
            '-d', 'memory_limit='.(string) \ini_get('memory_limit'),
            $binary,
        ];
    }

    /**
     * The flags that give the child this run's verbosity, colours and interaction.
     *
     * @return list<string>
     */
    private static function console(bool $interactive, OutputInterface $output): array
    {
        $verbosity = $output->getVerbosity();
        $flags = match (true) {
            $verbosity <= OutputInterface::VERBOSITY_QUIET => ['--quiet'],
            $verbosity >= OutputInterface::VERBOSITY_DEBUG => ['-vvv'],
            $verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE => ['-vv'],
            $verbosity >= OutputInterface::VERBOSITY_VERBOSE => ['-v'],
            default => [],
        };
        $flags[] = $output->isDecorated() ? '--ansi' : '--no-ansi';
        if (!$interactive) {
            $flags[] = '--no-interaction';
        }

        return $flags;
    }

    /**
     * Runs one child to its end.
     *
     * A person at a terminal gets the child on that terminal, so its prompts
     * reach them. Otherwise its output is copied as it arrives. The child did
     * its own verbosity filtering, so the copy passes at every level.
     *
     * @param list<string> $argv the whole command line
     * @param string       $line the command as a person types it after `composer`
     *
     * @return string why it failed, empty when it exited 0
     */
    private static function one(array $argv, string $line, bool $interactive, OutputInterface $output): string
    {
        $process = new Process($argv, timeout: null);
        if ($interactive && Process::isTtySupported() && $output instanceof StreamOutput && \stream_isatty($output->getStream())) {
            $process->setTty(true);
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $code = $process->run(static function (string $type, string $buffer) use ($output, $errors): void {
                (Process::ERR === $type ? $errors : $output)->write($buffer, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
            });
        } catch (Throwable $e) {
            return Text::t('composer @command stopped: @why', ['@command' => $line, '@why' => $e->getMessage()]);
        }

        return 0 === $code ? '' : Text::t('composer @command exited @code', ['@command' => $line, '@code' => $code]);
    }
}
