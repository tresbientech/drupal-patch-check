<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * What the command says when the service answers with an error status.
 */
class ServiceErrorTest extends TestCase
{
    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->site = null;
        $this->server = null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function drive(int $status, array $body): CommandTester
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $this->server = new PlanServer($body, $status);
        $composer = $this->site->enter($this->server->endpoint);

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testNamesTheReasonTheServerGave(): void
    {
        $tester = $this->drive(502, ['error' => 'patch check unreachable']);

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('127.0.0.1 answered 502 (patch check unreachable), patches not checked', $tester->getDisplay());
    }

    public function testTheProductionReasonIsPrintedWhole(): void
    {
        $reason = 'patch check unreachable: Post "http://192.168.0.108:6071/check": dial tcp 192.168.0.108:6071: connect: connection refused';
        $tester = $this->drive(400, ['error' => $reason]);

        self::assertStringContainsString('answered 400 ('.$reason.')', $tester->getDisplay());
    }

    public function testABodyWithoutAReasonKeepsTheShortLine(): void
    {
        $tester = $this->drive(503, ['status' => 'down']);

        self::assertStringContainsString('127.0.0.1 answered 503, patches not checked', $tester->getDisplay());
    }
}
