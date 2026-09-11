<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Composer;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Read\Run;

/**
 * What a composer update says about a declaration anyone with a drupal.org
 * account can push to, whatever the site configured.
 */
#[CoversClass(Plugin::class)]
class HookWarningTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch';

    private const SENTENCE = '1 patch is declared as a merge request URL. Anyone with a drupal.org';

    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->site = null;
        $this->server = null;
    }

    private static function update(Composer $composer): string
    {
        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostUpdate(new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io));

        return $io->getOutput();
    }

    private static function install(Composer $composer): string
    {
        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostInstall(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));

        return $io->getOutput();
    }

    // The site never asked for a report, and the risk is there anyway.
    public function testASiteThatNeverEnabledTheHookIsToldAnyway(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', self::MR);

        $out = self::update($this->site->enter('http://127.0.0.1:1/never-called'));

        self::assertStringContainsString(self::SENTENCE, $out);
        self::assertStringContainsString('change between two installs. Run: composer drupatch:pin', $out);
        // The verdict report is what the hook setting still gates.
        self::assertStringNotContainsString('Drupal Patch Check', $out);
    }

    // A CI job installs from the lock and pulls the merge request afresh,
    // so it is told what it took.
    public function testAnInstallIsToldToo(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', self::MR);

        $out = self::install($this->site->enter('http://127.0.0.1:1/never-called'));

        self::assertSame(1, \substr_count($out, self::SENTENCE));
    }

    public function testAnInstallOverALocalPatchHearsNothing(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        self::assertSame('', self::install($this->site->enter('http://127.0.0.1:1/never-called')));
    }

    public function testASiteDeclaringNoMergeRequestHearsNothing(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        self::assertSame('', self::update($this->site->enter('http://127.0.0.1:1/never-called')));
    }

    // The warning is not the report, and a site with both set sees each once.
    public function testASiteWithTheHookOnHearsItOnceAndThenTheReport(): void
    {
        $this->site = (new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            // The merge request is declared on a package the lock does not
            // hold, so the run skips its text rather than reaching the host.
            ->withExtra('patches', [
                'drupal/token' => ['3521733: bfcache' => self::MR],
                'drupal/webform' => ['Fix' => 'patches/webform/fix.patch'],
            ])
            ->withExtra('drupal-patch-check', ['hook' => true]);
        $this->server = new PlanServer([
            'target_core' => '10.6.9',
            'core_installed' => '10.6.9',
            'target_is_installed' => true,
            'counts' => [],
            'plan' => ['counts' => ['conflicts' => 1], 'patches' => [[
                'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9',
                'source' => '', 'verdict' => 'conflicts',
            ]]],
        ]);

        $out = self::update($this->site->enter($this->server->endpoint));

        self::assertSame(1, \substr_count($out, self::SENTENCE));
        self::assertStringContainsString('1 conflicts after this update', $out);
    }

    // 2.x records a hash per patch and refuses bytes that moved, so a site
    // on it hears nothing about a merge request URL.
    public function testASiteOnTheTwoLineHearsNothing(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', self::MR)->withManager('2.0.0');

        self::assertSame('', self::update($this->site->enter('http://127.0.0.1:1/never-called')));
    }

    public function testASiteOnTheOneLineIsStillTold(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', self::MR)->withManager('1.7.3');

        self::assertStringContainsString(self::SENTENCE, self::update($this->site->enter('http://127.0.0.1:1/never-called')));
    }

    // A site on 1.x keeping its patches in a file is warned about them too.
    public function testAPatchesFileOnTheOneLineIsCounted(): void
    {
        $this->site = (new SiteFixture())
            ->declares('3521733: bfcache', self::MR)
            ->withManager('1.7.3')
            ->inPatchesFile('patches.json');

        self::assertStringContainsString(self::SENTENCE, self::update($this->site->enter('http://127.0.0.1:1/never-called')));
    }
}
