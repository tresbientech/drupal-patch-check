<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Command\PinCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Plugin;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Service\Client;
use TresBienTech\Drupatch\Tests\Golden;
use TresBienTech\Drupatch\Tests\StubHost;

/**
 * What a site operator sees, per command: stdout, stderr and the exit code.
 *
 * The render goldens hold the words. These hold the contract around them,
 * which the command layer owns: which stream a line goes to, and what the
 * run exits with. Colour tags are stripped here, since the tester leaves
 * output undecorated.
 */
class CommandGoldenTest extends TestCase
{
    use Golden;

    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    private string|false $columns = false;

    protected function setUp(): void
    {
        // The report measures the terminal, so a run at 120 columns and CI
        // at 80 would render different tables.
        $this->columns = \getenv('COLUMNS');
        \putenv('COLUMNS=100');
    }

    protected function tearDown(): void
    {
        \putenv(false === $this->columns ? 'COLUMNS' : 'COLUMNS='.$this->columns);
        $this->site?->leave();
        $this->server?->stop();
        $this->site = null;
        $this->server = null;
    }

    public function testCheck(): void
    {
        $tester = $this->check($this->conflicted(), 200, []);

        self::assertGolden('cmd-check', $this->captured($tester));
    }

    public function testCheckAsJson(): void
    {
        $tester = $this->check($this->conflicted(), 200, ['--format' => 'json']);

        self::assertGolden('cmd-check-json', $this->captured($tester));
    }

    public function testCheckWhenTheServiceFails(): void
    {
        $tester = $this->check(['error' => 'the request was too large'], 502, []);

        self::assertGolden('cmd-check-service-error', $this->captured($tester));
    }

    public function testCheckDryRun(): void
    {
        $tester = $this->check($this->conflicted(), 200, ['--dry-run' => true]);

        self::assertGolden('cmd-check-dry-run', $this->captured($tester));
    }

    public function testHook(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')
            ->withExtra('drupal-patch-check', ['hook' => true]);
        $this->server = new PlanServer($this->conflicted());
        $composer = $this->site->enter($this->server->endpoint);

        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostUpdate(new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io));

        self::assertGolden('cmd-hook', $this->scrub($io->getOutput()));
    }

    public function testPin(): void
    {
        $mr = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch';
        $this->site = (new SiteFixture())->declares('3521733: bfcache', $mr)->inGit();
        $composer = $this->site->enter('http://127.0.0.1:1');
        $this->site->write('patch/webform/mr940.diff', "diff --git a/x b/x\n");

        $command = new class extends PinCommand {
            protected function site(): Site
            {
                return Site::at($this->requireComposer(), $this->getIO(), Factory::getComposerFile(), StubHost::fetch([]));
            }
        };
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertGolden('cmd-pin', $this->captured($tester));
    }

    public function testUpgradeDryRun(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')->withManager('1.7.3');
        $this->server = new PlanServer($this->conflicted());
        $composer = $this->site->enter($this->server->endpoint);

        $command = new UpgradeCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true], ['capture_stderr_separately' => true]);

        self::assertGolden('cmd-upgrade-dry-run', $this->captured($tester));
    }

    // The cases below run on 1.x of the patch manager, each through a branch
    // that reads its version.

    // Installing the plugin prints the move prompt after the notice.
    public function testPluginInstalledOnOne(): void
    {
        $this->site = (new SiteFixture())->withManager('1.7.3');
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');
        // Each block prints once per site, and the marker saying so lives in this cache.
        $composer->getConfig()->merge(['config' => ['cache-dir' => $this->site->root.'/cache']]);

        $io = new BufferIO();
        // A run nobody is watching prints neither block.
        $io->setUserInputs([]);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPackageInstall(new PackageEvent(
            PackageEvents::POST_PACKAGE_INSTALL,
            $composer,
            $io,
            false,
            $composer->getRepositoryManager()->getLocalRepository(),
            [],
            new InstallOperation(new Package(Client::PACKAGE, '0.21.0.0', '0.21.0')),
        ));

        self::assertGolden('cmd-plugin-installed-on-one', $this->scrub($io->getOutput()));
    }

    // 1.x keeps no hash per patch, so an install says what it pulled from a
    // merge request.
    public function testInstallOnOne(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch')->withManager('1.7.3');
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');

        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostInstall(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));

        self::assertGolden('cmd-install-on-one', $this->scrub($io->getOutput()));
    }

    // The merge request warning prints ahead of the hook's report.
    public function testHookOnOne(): void
    {
        // The merge request is declared on a package the lock does not hold,
        // so the run reads no text for it.
        $this->site = (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')
            ->declaresOn('drupal/token', '3521733: bfcache', 'https://git.drupalcode.org/project/token/-/merge_requests/12.patch')
            ->withManager('1.7.3')
            ->withExtra('drupal-patch-check', ['hook' => true]);
        $this->server = new PlanServer($this->conflicted());
        $composer = $this->site->enter($this->server->endpoint);

        $io = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPostUpdate(new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io));

        self::assertGolden('cmd-hook-on-one', $this->scrub($io->getOutput()));
    }

    // 1.x applies loosely, so a strict refusal still applies and the run
    // exits clean.
    public function testCheckStrictRefusalOnOne(): void
    {
        $plan = $this->conflicted();
        $plan['plan'] = ['counts' => ['applies' => 1], 'patches' => [[
            'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9',
            'source' => '', 'verdict' => 'applies',
            'result' => ['fuzzy' => true, 'strict_refused' => 'the patch carries the packaging block as context'],
        ]]];

        $tester = $this->check($plan, 200, [], (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')->withManager('1.7.3'));

        self::assertGolden('cmd-check-strict-refusal-on-one', $this->captured($tester));
    }

    // 1.x reads its patches file from extra.patches-file.
    public function testCheckPatchesFileOnOne(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix from the patches file', 'patches/webform/fix.patch')->withManager('1.7.3')->inPatchesFile('patches.json');

        $tester = $this->check($this->conflicted(), 200, [], $site);

        self::assertGolden('cmd-check-patches-file-on-one', $this->captured($tester));
    }

    // 2.x leaves a source it cannot read to composer patches-doctor. 1.x has
    // no such command, so the skip carries its reason. The check reads the
    // site itself and takes no stub host, so the source is a missing file.
    public function testCheckUnreadablePatchOnOne(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')
            ->declares('Access fix', 'patches/webform/missing.patch')
            ->withManager('1.7.3');

        $tester = $this->check($this->conflicted(), 200, [], $site);

        self::assertGolden('cmd-check-unreadable-on-one', $this->captured($tester));
    }

    // 1.x has no patches-relock, so add stops before any host is asked.
    public function testAddOnOne(): void
    {
        $this->site = (new SiteFixture())->withManager('1.7.3');
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');

        $tester = $this->drive(new AddCommand(), $composer, ['issue' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940']);

        self::assertGolden('cmd-add-on-one', $this->captured($tester));
    }

    // A compact declaration holds no record, so the old `# drupatch` line
    // stays in the copy and the declaration names the file alone.
    public function testPinOnOne(): void
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch')->withManager('1.7.3');
        $composer = $this->site->enter('http://127.0.0.1:1');
        $this->site->write('patch/webform/mr940.diff', "# drupatch {\"mr\":\"https://git.drupalcode.org/project/webform/-/merge_requests/940\"}\ndiff --git a/x b/x\n");

        $command = new class extends PinCommand {
            protected function site(): Site
            {
                return Site::at($this->requireComposer(), $this->getIO(), Factory::getComposerFile(), StubHost::fetch([]));
            }
        };
        // --force asks git nothing.
        $tester = $this->drive($command, $composer, ['--force' => true]);

        self::assertGolden('cmd-pin-on-one', $this->captured($tester)."\n".$this->written(['composer.json', 'patch/webform/mr940.diff']));
    }

    // 1.x keeps no record to merge from, so a conflicting patch titled with
    // an issue number is re-rolled where it stands, and no host is asked for
    // its merge request.
    public function testRerollOnOne(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('3521733: Fix the alter hook', 'patches/webform/fix.patch')->withManager('1.7.3');
        $plan = $this->conflicted();
        $plan['plan']['patches'][0]['result']['reroll'] = ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true];
        $this->server = new PlanServer($plan);
        $composer = $this->site->enter($this->server->endpoint);

        $tester = $this->drive(new RerollCommand(), $composer, ['--force' => true]);

        self::assertGolden('cmd-reroll-on-one', $this->captured($tester)."\n".$this->written(['composer.json', 'patches/webform/fix.patch']));
    }

    // The move reads a patches file the site already names from the 1.x key.
    public function testUpgradeDryRunWithAPatchesFile(): void
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')->withManager('1.7.3')->inPatchesFile('patches/site.json');
        $this->server = new PlanServer($this->conflicted());
        $composer = $this->site->enter($this->server->endpoint);

        $tester = $this->drive(new UpgradeCommand(), $composer, ['--dry-run' => true]);

        self::assertGolden('cmd-upgrade-dry-run-patches-file', $this->captured($tester));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function drive(BaseCommand $command, Composer $composer, array $input): CommandTester
    {
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * The files as the run left them, each under its path.
     *
     * @param list<string> $paths
     */
    private function written(array $paths): string
    {
        $out = [];
        foreach ($paths as $path) {
            $out[] = '--- '.$path."\n".\rtrim((string) $this->site?->read($path));
        }

        return \implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $input
     */
    private function check(array $plan, int $status, array $input, ?SiteFixture $site = null): CommandTester
    {
        $this->site = $site ?? (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch');
        $this->server = new PlanServer($plan, $status);
        $composer = $this->site->enter($this->server->endpoint);

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function conflicted(): array
    {
        return [
            'target_core' => '11.4.5',
            'core_installed' => '11.4.5',
            'target_is_installed' => true,
            'counts' => [],
            'plan' => ['counts' => ['conflicts' => 1], 'patches' => [[
                'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9',
                'source' => '', 'verdict' => 'conflicts',
                'result' => ['hunks_failed' => [['file' => 'webform.module', 'line' => 165, 'reason' => 'patch failed']]],
            ]]],
        ];
    }

    private function captured(CommandTester $tester): string
    {
        return $this->scrub(\sprintf(
            "--- stdout\n%s\n--- stderr\n%s\n--- exit\n%d",
            \rtrim($tester->getDisplay()),
            \rtrim($tester->getErrorOutput()),
            $tester->getStatusCode(),
        ));
    }

    /** The site root and the stub server's port are new on every run. */
    private function scrub(string $text): string
    {
        $text = \rtrim($text);
        if (null !== $this->site) {
            $text = \str_replace($this->site->root, '<site>', $text);
        }
        if (null !== $this->server) {
            $text = \str_replace($this->server->endpoint, '<endpoint>', $text);
            $text = \preg_replace('/127\.0\.0\.1:\d+/', '127.0.0.1:<port>', $text) ?? $text;
        }

        return $text;
    }
}
