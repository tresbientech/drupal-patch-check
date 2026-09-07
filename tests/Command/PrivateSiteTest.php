<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;

/**
 * A site that set `extra.drupal-patch-check.private-paths`, driven
 * end to end: what the request holds, and what the report reads back.
 */
final class PrivateSiteTest extends TestCase
{
    /** A title and a path that between them name a client and a ticket. */
    private const TITLE = 'CUP-1341: SVG rendering in the DAM';

    private const SOURCE = 'patch/acquia_dam/CUP-1341_svg.patch';

    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->site?->leave();
        $this->site = null;
    }

    public function testTheDeclarationsTravelAsPlaceholders(): void
    {
        $body = (array) \json_decode($this->drive(['--dry-run' => true])->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([['package' => 'drupal/webform', 'source' => 'p0']], $body['patch_config']);
        self::assertSame(['p0'], \array_keys((array) $body['patch_files']));
    }

    // Three fields held these strings, and the copy inside composer_json
    // was the one no test would have caught. The whole body is searched
    // rather than the field that is known about.
    public function testNothingInTheWholeRequestNamesTheTitleOrThePath(): void
    {
        $body = $this->drive(['--dry-run' => true])->getDisplay();

        self::assertStringNotContainsString('CUP-1341', $body);
        self::assertStringNotContainsString('acquia_dam', $body);
    }

    public function testTheKeyUnsetStillSendsTheDeclarationsAsWritten(): void
    {
        $body = $this->drive(['--dry-run' => true], private: false)->getDisplay();

        self::assertStringContainsString('CUP-1341', $body);
        self::assertStringContainsString('acquia_dam', $body);
    }

    public function testTheReportReadsBackTheSitesOwnWords(): void
    {
        $this->server = new PlanServer(['target_core' => '11.4.5', 'plan' => [
            'counts' => ['applies' => 1],
            'patches' => [[
                'package' => 'drupal/webform',
                'project' => 'webform',
                'version' => '6.2.9',
                'source' => 'p0',
                'verdict' => 'applies',
            ]],
        ]]);

        $display = $this->drive([])->getDisplay();

        self::assertStringContainsString(self::TITLE, $display);
        self::assertStringNotContainsString('p0', $display);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function drive(array $input, bool $private = true): CommandTester
    {
        $this->site = (new SiteFixture())
            ->declaresPatch(self::TITLE, self::SOURCE)
            ->withExtra('drupal-patch-check', ['private-paths' => $private]);
        // A dry run asks nothing of the service, so it needs no server.
        $composer = $this->site->enter(null === $this->server ? 'http://127.0.0.1:1/v1/composer/scan' : $this->server->endpoint);

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
