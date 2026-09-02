<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * What `--dry-run` puts on each stream. Nothing is asked of the service,
 * so no plan server runs.
 */
final class DryRunTest extends TestCase
{
    private ?SiteFixture $site = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->site = null;
    }

    // The request body is piped into jq, so a line meant for a person
    // sharing stdout with it makes the whole run unreadable.
    public function testStdoutCarriesTheRequestAndNothingElse(): void
    {
        $tester = $this->drive();

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertIsArray(\json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheLinesMeantForAPersonGoToStderr(): void
    {
        $tester = $this->drive();

        self::assertStringContainsString('extra.patches-search is not read', $tester->getErrorOutput());
    }

    private function drive(): CommandTester
    {
        $this->site = (new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            // Any note will do; this one needs nothing of the service.
            ->withExtra('patches-search', ['drupal/webform' => 'patches/webform']);
        $composer = $this->site->enter('http://127.0.0.1:1/v1/composer/scan');

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
