<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Render\Report;

class CheckCommandTest extends TestCase
{
    public function testTheNameSaysWhatItDoesAndTheOldSpellingStillReaches(): void
    {
        $command = new CheckCommand();

        self::assertSame('drupatch:check', $command->getName());
        self::assertSame(['drupal-patch-check'], $command->getAliases());
    }

    public function testTheHookPointsAtTheCommandThatExists(): void
    {
        self::assertSame('composer '.(new CheckCommand())->getName(), Report::COMMAND);
    }

    public function testTheCheckTakesTheSharedOptionsAndNothingThatWrites(): void
    {
        $definition = (new CheckCommand())->getDefinition();

        foreach (['target', 'package', 'format', 'dry-run'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
        }
        foreach (['write', 'fix', 'resolve', 'strict', 'json', 'force', 'update', 'decisions', 'refresh'] as $option) {
            self::assertFalse($definition->hasOption($option), $option.' is an option of the read command');
        }
    }

    public function testTheRerollTakesTheSharedOptionsAndTheOnesThatWrite(): void
    {
        $definition = (new RerollCommand())->getDefinition();

        foreach (['target', 'package', 'format', 'dry-run', 'force'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
        }
        foreach (['write', 'fix', 'resolve', 'strict', 'json'] as $option) {
            self::assertFalse($definition->hasOption($option), $option.' came back');
        }
    }

    // The console refuses --write before the run reads the site.
    public function testARemovedOptionGetsTheConsolesOwnRefusal(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The "--write" option does not exist.');

        $command = new RerollCommand();
        $command->setApplication(new Application());

        (new CommandTester($command))->execute(['--write' => true]);
    }

    // A reviewer approving the plugin for CI reads the request rather
    // than the README, so the flag takes no value and asks nothing.
    public function testDryRunIsAFlag(): void
    {
        $option = (new CheckCommand())->getDefinition()->getOption('dry-run');

        self::assertFalse($option->acceptValue());
    }

    // --package is repeatable, because a person updating two modules
    // should not have to run the command twice.
    public function testThePackageOptionTakesMoreThanOne(): void
    {
        $option = (new CheckCommand())->getDefinition()->getOption('package');

        self::assertTrue($option->isArray());
        self::assertTrue($option->isValueRequired());
    }
}
