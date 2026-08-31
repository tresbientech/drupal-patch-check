<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Render\HookReport;

final class CheckCommandTest extends TestCase
{
    public function testTheNameSaysWhatItDoesAndThePackageSpellingStillWorks(): void
    {
        $command = new CheckCommand();

        self::assertSame('drupal-patch-check', $command->getName());
        self::assertContains('drupatch-check', $command->getAliases());
    }

    public function testTheHookPointsAtTheCommandThatExists(): void
    {
        self::assertSame('composer '.(new CheckCommand())->getName(), HookReport::COMMAND);
    }

    public function testEveryOptionTheReportPromisesIsDefined(): void
    {
        $definition = (new CheckCommand())->getDefinition();

        foreach (['target', 'write', 'fix', 'force', 'json', 'package', 'strict', 'dry-run', 'resolve'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
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

    public function testResolveIsAFlag(): void
    {
        self::assertFalse((new CheckCommand())->getDefinition()->getOption('resolve')->acceptValue());
    }
}
