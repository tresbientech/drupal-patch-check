<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Command\PatchCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * Every command that re-rolls takes the same two flags on test files, and
 * refuses the two together.
 */
class TestFilesFlagsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<PatchCommand>, array<string, string>}>
     */
    public static function rerolling(): iterable
    {
        yield 'reroll' => [RerollCommand::class, []];
        yield 'add' => [AddCommand::class, ['issue' => 'https://www.drupal.org/project/webform/issues/3500000']];
        yield 'upgrade' => [UpgradeCommand::class, []];
    }

    /**
     * @param class-string<PatchCommand> $class
     * @param array<string, string>      $arguments
     */
    #[DataProvider('rerolling')]
    public function testTheCommandTakesBothFlags(string $class, array $arguments): void
    {
        $definition = (new $class())->getDefinition();

        foreach (['drop-tests', 'keep-tests'] as $flag) {
            self::assertTrue($definition->hasOption($flag), $flag);
            self::assertFalse($definition->getOption($flag)->acceptValue(), $flag);
        }
    }

    // Neither flag can win over the other, so the run stops before it
    // reads the site or asks any host.
    /**
     * @param class-string<PatchCommand> $class
     * @param array<string, string>      $arguments
     */
    #[DataProvider('rerolling')]
    public function testBothFlagsTogetherAreRefused(string $class, array $arguments): void
    {
        $command = new $class();
        $command->setApplication(new Application());
        $tester = new CommandTester($command);

        $tester->execute($arguments + ['--drop-tests' => true, '--keep-tests' => true], ['capture_stderr_separately' => true]);

        self::assertNotSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertStringContainsString('--drop-tests and --keep-tests', $tester->getDisplay().$tester->getErrorOutput());
    }
}
