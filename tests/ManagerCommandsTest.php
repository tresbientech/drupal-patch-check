<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Tests\Command\ComposerStub;

/**
 * The manager's commands, each run as a child of the composer that started this run.
 */
#[CoversClass(ManagerCommands::class)]
class ManagerCommandsTest extends TestCase
{
    private ComposerStub $composer;

    protected function setUp(): void
    {
        $this->composer = new ComposerStub();
    }

    protected function tearDown(): void
    {
        $this->composer->leave();
    }

    public function testEachCommandRunsInOrderAndTheRunSaysWhichFinished(): void
    {
        $ran = ManagerCommands::run(ManagerCommands::APPLY, false, new BufferedOutput());

        self::assertSame(['ran' => [ManagerCommands::RELOCK, ManagerCommands::REPATCH], 'error' => '', 'left' => []], $ran);
        self::assertSame([ManagerCommands::RELOCK, ManagerCommands::REPATCH], $this->composer->commands());
    }

    // A person watching sees which command the output below belongs to.
    public function testEachChildsOutputFollowsTheLineNamingIt(): void
    {
        $output = new BufferedOutput();

        ManagerCommands::run(ManagerCommands::APPLY, false, $output);

        self::assertSame(
            "  -> composer patches-relock\nstub ran patches-relock\nstub notes patches-relock\n"
            ."  -> composer patches-repatch\nstub ran patches-repatch\nstub notes patches-repatch\n",
            $output->fetch()
        );
    }

    public function testAFailingCommandStopsTheRunAndLeavesItAndTheRestToDo(): void
    {
        $this->composer->failing(ManagerCommands::RELOCK);

        $ran = ManagerCommands::run(ManagerCommands::APPLY, false, new BufferedOutput());

        self::assertSame([
            'ran' => [],
            'error' => 'composer patches-relock exited '.ComposerStub::FAILED,
            'left' => [ManagerCommands::RELOCK, ManagerCommands::REPATCH],
        ], $ran);
        self::assertSame([ManagerCommands::RELOCK], $this->composer->commands());
    }

    // A dependency update is the step that needs memory, and a person who
    // raised the limit on the parent raised it for this.
    public function testTheChildRunsUnderTheParentsMemoryLimit(): void
    {
        $limit = (string) \ini_get('memory_limit');
        \ini_set('memory_limit', '321M');
        try {
            ManagerCommands::run([[ManagerCommands::RELOCK]], false, new BufferedOutput());
        } finally {
            \ini_set('memory_limit', $limit);
        }

        self::assertSame('321M', $this->composer->runs()[0]['memory_limit']);
    }

    public function testTheParentsConsoleSettingsReachTheChild(): void
    {
        ManagerCommands::run([[ManagerCommands::RELOCK]], false, new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE, false));
        ManagerCommands::run([[ManagerCommands::REPATCH]], true, new BufferedOutput(OutputInterface::VERBOSITY_QUIET, true));

        self::assertSame([
            [ManagerCommands::RELOCK, '-vv', '--no-ansi', '--no-interaction'],
            [ManagerCommands::REPATCH, '--quiet', '--ansi'],
        ], \array_column($this->composer->runs(), 'args'));
    }

    // Each command was named as it started, so a run that finished adds nothing.
    public function testARunThatFinishedAddsNoLine(): void
    {
        self::assertSame([], ManagerCommands::stopped(['ran' => [ManagerCommands::RELOCK, ManagerCommands::REPATCH], 'error' => '', 'left' => []]));
    }

    // The declarations are written by then, so the run says what is left to do.
    public function testARunThatStoppedSaysWhatIsLeft(): void
    {
        self::assertSame([
            '  <error>composer patches-relock exited 1</error>',
            '  run `composer patches-relock` then `composer patches-repatch` to finish',
        ], ManagerCommands::stopped(['ran' => [], 'error' => 'composer patches-relock exited 1', 'left' => [ManagerCommands::RELOCK, ManagerCommands::REPATCH]]));
        self::assertSame([
            '  <error>composer patches-repatch exited 1</error>',
            '  run `composer patches-repatch` to finish',
        ], ManagerCommands::stopped(['ran' => [ManagerCommands::RELOCK], 'error' => 'composer patches-repatch exited 1', 'left' => [ManagerCommands::REPATCH]]));
    }

    public function testTheMoveInstallsTheManagerAndItsOwnDependenciesFirst(): void
    {
        ManagerCommands::run(ManagerCommands::MOVE, false, new BufferedOutput());

        self::assertSame(
            [['update', 'cweagans/composer-patches', '--with-dependencies'], [ManagerCommands::RELOCK], [ManagerCommands::REPATCH]],
            \array_map(static fn (array $run): array => \array_slice($run['args'], 0, -2), $this->composer->runs())
        );
    }

    public function testARunWithNoComposerBinaryNamedStartsNothing(): void
    {
        $this->composer->unnamed();

        $ran = ManagerCommands::run(ManagerCommands::APPLY, false, new BufferedOutput());

        self::assertSame([
            'ran' => [],
            'error' => 'COMPOSER_BINARY is unset, so this run cannot start composer patches-relock',
            'left' => [ManagerCommands::RELOCK, ManagerCommands::REPATCH],
        ], $ran);
    }
}
