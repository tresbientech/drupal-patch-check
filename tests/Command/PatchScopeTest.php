<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\RerollCommand;
use TresBienTech\Drupatch\Run;

/**
 * --patch narrows both commands to one declaration, named by its source.
 */
#[CoversClass(Run::class)]
class PatchScopeTest extends TestCase
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
     * A site with three patches on one package, every one of them re-rolled clean by the stub, which reports the verdict a clean re-roll reaches.
     *
     * @param array<string, mixed> $input
     */
    private function drive(CheckCommand|RerollCommand $command, array $input, bool $serve = true): CommandTester
    {
        $this->site = (new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            ->declaresPatch('Menu', 'patches/webform/menu.patch')
            ->declaresPatch('Cache', 'patches/webform/cache.patch');
        $endpoint = 'http://127.0.0.1:1/never-called';
        if ($serve) {
            $rows = [];
            foreach ([['Fix', 'patches/webform/fix.patch'], ['Menu', 'patches/webform/menu.patch'], ['Cache', 'patches/webform/cache.patch']] as [$title, $source]) {
                $rows[] = [
                    'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9',
                    'title' => $title, 'source' => $source, 'verdict' => 'applies',
                    'result' => ['reroll' => ['status' => 'clean', 'verified' => true, 'patch' => "diff --git a/y b/y\n--- a/y\n+++ b/y\n@@ -1 +1 @@\n-a\n+".$title."\n"]],
                ];
            }
            $this->server = new PlanServer([
                'target_core' => '10.6.9', 'core_installed' => '10.6.9', 'target_is_installed' => true, 'counts' => [],
                'plan' => ['counts' => ['applies' => 3], 'patches' => $rows],
            ]);
            $endpoint = $this->server->endpoint;
        }
        $composer = $this->site->enter($endpoint);

        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTheCheckNarrowsToTheOnePatch(): void
    {
        $tester = $this->drive(new CheckCommand(), ['--patch' => ['patches/webform/menu.patch'], '--json' => true]);

        $document = \json_decode($tester->getDisplay(), true);
        self::assertIsArray($document);
        self::assertCount(1, $document['plan']['patches']);
        self::assertSame('patches/webform/menu.patch', $document['plan']['patches'][0]['source']);
        self::assertSame(['patches/webform/menu.patch'], $document['scope_patches']);
        self::assertSame([], $document['scope']);
    }

    public function testTheRerollWritesTheOnePatchAlone(): void
    {
        $tester = $this->drive(new RerollCommand(), ['--patch' => ['patches/webform/cache.patch'], '--force' => true]);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('+Cache', $this->site?->read('patches/webform/cache.patch') ?? '');
        self::assertStringNotContainsString('+Fix', $this->site?->read('patches/webform/fix.patch') ?? '');
        self::assertStringNotContainsString('+Menu', $this->site?->read('patches/webform/menu.patch') ?? '');
    }

    public function testAPackageAndAPatchCombine(): void
    {
        $tester = $this->drive(new CheckCommand(), ['--package' => ['webform'], '--patch' => ['patches/webform/fix.patch'], '--json' => true]);

        $document = \json_decode($tester->getDisplay(), true);
        self::assertIsArray($document);
        self::assertSame(['patches/webform/fix.patch'], \array_column($document['plan']['patches'], 'source'));
        self::assertSame(['webform'], $document['scope']);
        self::assertSame(['patches/webform/fix.patch'], $document['scope_patches']);
    }

    public function testAnUndeclaredSourceStopsBeforeTheServiceIsAsked(): void
    {
        $tester = $this->drive(new CheckCommand(), ['--patch' => ['patches/webform/gone.patch']], false);

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('no patch is declared from patches/webform/gone.patch', $display);
        self::assertStringContainsString('patches/webform/fix.patch, patches/webform/menu.patch, patches/webform/cache.patch', $display);
    }
}
