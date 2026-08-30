<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Command;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Command\CheckCommand;
use Tresbien\Drupatch\Render\HookReport;

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

        foreach (['target', 'reroll', 'fix', 'force', 'json'] as $option) {
            self::assertTrue($definition->hasOption($option), $option.' is not an option');
        }
    }
}
