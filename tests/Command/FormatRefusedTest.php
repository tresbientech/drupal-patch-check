<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Command\PinCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Plan\Plan;

final class FormatRefusedTest extends TestCase
{
    protected function setUp(): void
    {
        // No composer is set on these commands, so a run that got past the
        // shape would read the site the test runs in and call out from it.
        \putenv('DRUPATCH_ENDPOINT=http://127.0.0.1:1/never-called');
    }

    protected function tearDown(): void
    {
        \putenv('DRUPATCH_ENDPOINT');
    }

    public function testEveryCommandRefusesAnUnknownShapeBeforeItReadsTheSite(): void
    {
        foreach ([new CheckCommand(), new RerollCommand(), new PinCommand()] as $command) {
            $command->setApplication(new Application());
            $tester = new CommandTester($command);

            $tester->execute(['--format' => 'github'], ['capture_stderr_separately' => true]);

            $name = (string) $command->getName();
            self::assertSame(Plan::FAILED, $tester->getStatusCode(), $name);
            self::assertStringContainsString('unknown --format=github; accepted: table, json', $tester->getDisplay(), $name);
        }
    }
}
