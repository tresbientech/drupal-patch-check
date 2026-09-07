<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Render\HookReport;
use TresBienTech\Drupatch\RerollCommand;

final class CheckCommandTest extends TestCase
{
    public function testTheNameSaysWhatItDoesAndTheOldSpellingStillReaches(): void
    {
        $command = new CheckCommand();

        self::assertSame('drupatch:check', $command->getName());
        self::assertSame(['drupal-patch-check'], $command->getAliases());
    }

    public function testTheHookPointsAtTheCommandThatExists(): void
    {
        self::assertSame('composer '.(new CheckCommand())->getName(), HookReport::COMMAND);
    }

    public function testTheCheckTakesTheSharedOptionsAndNothingThatWrites(): void
    {
        $definition = (new CheckCommand())->getDefinition();

        foreach (['target', 'package', 'json', 'format', 'dry-run'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
        }
        foreach (['write', 'fix', 'resolve', 'strict', 'force', 'update', 'decisions'] as $option) {
            self::assertFalse($definition->hasOption($option), $option.' is an option of the read command');
        }
    }

    public function testTheRerollTakesTheSharedOptionsAndTheOnesThatWrite(): void
    {
        $definition = (new RerollCommand())->getDefinition();

        foreach (['target', 'package', 'json', 'format', 'dry-run', 'update', 'force'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
        }
        foreach (['write', 'fix', 'resolve', 'strict'] as $option) {
            self::assertFalse($definition->hasOption($option), $option.' came back');
        }
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
