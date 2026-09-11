<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\ManagerCommands;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\UpgradeReport;
use TresBienTech\Drupatch\Settings;
use TresBienTech\Drupatch\Write\Upgrade;

/**
 * The move driven end to end against a site on disk, because the two
 * documents it leaves behind are its whole contract.
 */
#[CoversClass(UpgradeCommand::class)]
class UpgradeMoveTest extends TestCase
{
    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    private ComposerStub $composer;

    protected function setUp(): void
    {
        $this->composer = new ComposerStub();
    }

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->composer->leave();
        $this->site = null;
        $this->server = null;
    }

    /**
     * @param array<string, mixed> $row   what the service answers about the one declared patch
     * @param array<string, mixed> $input the options the run was given
     */
    private function drive(SiteFixture $site, array $row, array $input = []): CommandTester
    {
        $this->site = $site;
        $this->server = new PlanServer(['target_core' => '', 'counts' => [], 'rows' => [], 'plan' => [
            'counts' => [],
            'patches' => [['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => ''] + $row],
        ]]);

        $command = new UpgradeCommand();
        $command->setComposer($site->enter($this->server->endpoint));
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** A site on 1.x declaring one patch by path. */
    private static function onOne(): SiteFixture
    {
        return (new SiteFixture())->declaresPatch('Fix the alter hook', 'patches/webform/fix.patch')->withManager('1.7.3')->inGit();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        return (array) \json_decode($this->site?->read($path) ?? '', true);
    }

    /**
     * A patch a lenient apply took, with a clean re-roll waiting for it.
     *
     * @return array<string, mixed>
     */
    private static function fuzzy(): array
    {
        return ['verdict' => 'applies', 'result' => [
            'applies_at' => 1,
            'fuzzy' => true,
            'strict_refused' => 'context drifted',
            'reroll' => ['status' => 'clean', 'patch' => "rerolled\n", 'verified' => true],
        ]];
    }

    // The move asks for its re-rolls with the run's say on test files, and
    // with none when the run named no flag.
    public function testTheMoveAsksWithTheTestFilesChoiceTheRunNamed(): void
    {
        $this->drive(self::onOne(), self::fuzzy(), ['--keep-tests' => true]);

        self::assertFalse($this->server?->request()['drop_tests'] ?? null);
    }

    public function testAMoveNamingNoFlagSendsNoChoice(): void
    {
        $this->drive(self::onOne(), self::fuzzy());

        self::assertArrayNotHasKey('drop_tests', $this->server?->request() ?? []);
        self::assertTrue($this->server?->request()['reroll'] ?? null);
    }

    public function testTheMoveWritesBothDocumentsThenRunsTheManager(): void
    {
        $tester = $this->drive(self::onOne(), self::fuzzy());

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        $patches = $this->json(UpgradeCommand::PATCHES_FILE);
        self::assertSame(
            [['description' => 'Fix the alter hook', 'url' => 'patches/webform/fix.patch']],
            $patches['patches']['drupal/webform'] ?? null
        );
        $json = $this->json('composer.json');
        self::assertSame(Upgrade::CONSTRAINT, $json['require'][Manager::PACKAGE] ?? null);
        self::assertArrayNotHasKey('patches', (array) $json['extra']);
        self::assertSame(UpgradeCommand::PATCHES_FILE, $json['extra'][Settings::KEY]['patches-file'] ?? null);
        self::assertSame(['update', ManagerCommands::RELOCK, ManagerCommands::REPATCH], $this->composer->commands());
        self::assertSame(['update', Manager::PACKAGE, '--with-dependencies'], \array_slice($this->composer->runs()[0]['args'], 0, 3));
        self::assertStringContainsString('moved to cweagans/composer-patches 2.x', $tester->getDisplay());
    }

    // The documents stay: a composer run that stopped part way leaves the
    // lock and vendor in a state nothing here can see.
    public function testAFailedInstallLeavesTheDocumentsAndNamesWhatIsLeft(): void
    {
        $this->composer->failing('update');

        $tester = $this->drive(self::onOne(), self::fuzzy());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertSame(Upgrade::CONSTRAINT, $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
        self::assertTrue($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertSame(['update'], $this->composer->commands());
        self::assertStringContainsString(
            'run `composer update cweagans/composer-patches --with-dependencies` then `composer patches-relock` then `composer patches-repatch` to finish',
            $tester->getDisplay()
        );
    }

    public function testAFailedRelockLeavesItAndTheRepatch(): void
    {
        $this->composer->failing(ManagerCommands::RELOCK);

        $tester = $this->drive(self::onOne(), self::fuzzy());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('composer patches-relock exited '.ComposerStub::FAILED, $tester->getDisplay());
        self::assertStringContainsString('run `composer patches-relock` then `composer patches-repatch` to finish', $tester->getDisplay());
    }

    public function testADryRunNamesTheManagerCommandsAndRunsNone(): void
    {
        $tester = $this->drive(self::onOne(), self::fuzzy(), ['--dry-run' => true]);

        self::assertSame([], $this->composer->commands());
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertStringContainsString(
            "a real run finishes with:\n    composer update cweagans/composer-patches --with-dependencies\n    composer patches-relock\n    composer patches-repatch",
            $tester->getDisplay()
        );
    }

    // A dry run says what the real run will do with each strict refusal,
    // and only the re-roll answers that.
    public function testADryRunAsksForTheRerollsItReports(): void
    {
        $tester = $this->drive(self::onOne(), self::fuzzy(), ['--dry-run' => true]);

        self::assertTrue($this->server?->request()['reroll'] ?? null);
        self::assertStringContainsString('    drupal/webform    #1  re-rolls clean', $tester->getDisplay());
    }

    public function testARerollWithRegionsLeftIsCountedOnItsRow(): void
    {
        $reroll = ['status' => 'conflicts', 'patch' => '', 'conflicts' => [['file' => 'src/A.php', 'regions' => 2, 'hunks' => [
            ['release' => "one\n", 'patch' => "two\n", 'line' => 1],
            ['release' => "three\n", 'patch' => "four\n", 'line' => 9],
        ]]]];
        $tester = $this->drive(self::onOne(), ['verdict' => 'applies', 'result' => ['applies_at' => 1, 'fuzzy' => true, 'reroll' => $reroll]], ['--dry-run' => true]);

        self::assertStringContainsString('    drupal/webform    #1  leaves 2 regions to decide', $tester->getDisplay());
        self::assertStringContainsString(UpgradeReport::STOPS, $tester->getDisplay());
    }

    // The service is asked about drupal.org packages alone, and the patches
    // file replaces every declaration the site had.
    public function testAPatchTheServiceIsNeverAskedAboutMovesWithTheRest(): void
    {
        $this->drive(self::onOne()->declaresOn('psr/log', 'Mark the null logger', 'patches/psr-log.patch'), self::fuzzy());

        self::assertSame(
            [['description' => 'Mark the null logger', 'url' => 'patches/psr-log.patch']],
            $this->json(UpgradeCommand::PATCHES_FILE)['patches']['psr/log'] ?? null
        );
    }

    public function testAPatchTheServiceNeverJudgedIsNamedAsUnchecked(): void
    {
        $tester = $this->drive(self::onOne()->declaresOn('psr/log', 'Mark the null logger', 'patches/psr-log.patch'), ['verdict' => 'unknown'], ['--dry-run' => true]);

        self::assertStringContainsString('2 patches were not checked', $tester->getDisplay());
    }

    public function testTheRerolledPatchReplacesTheFileTheDeclarationNames(): void
    {
        $this->drive(self::onOne(), self::fuzzy());

        self::assertSame("rerolled\n", $this->site?->read('patches/webform/fix.patch'));
    }

    // The guard stands for a file somebody wrote, and the report says which
    // flag takes it, because the move cannot be run a second time.
    public function testAChangedPatchFileIsKeptAndTheFlagThatTakesItIsNamed(): void
    {
        $tester = $this->drive(self::onOne()->leavesChanged('patches/webform/fix.patch', "mine\n"), self::fuzzy());

        self::assertSame("mine\n", $this->site?->read('patches/webform/fix.patch'));
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    // 2.x would refuse the patch the guard kept, so the requirement stays
    // where the patch still applies.
    public function testARefusedCopyStopsBeforeTheRequirementMoves(): void
    {
        $tester = $this->drive(self::onOne()->leavesChanged('patches/webform/fix.patch', "mine\n"), self::fuzzy());

        self::assertSame('^1', $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertSame([], $this->composer->commands());
    }

    // A strict refusal the service sent no re-roll for never reaches the
    // write, and 2.x refuses it, so the move stops where the dry run says.
    public function testAStrictRefusalWithNoRerollStopsTheMove(): void
    {
        $tester = $this->drive(self::onOne(), ['verdict' => 'applies', 'result' => [
            'applies_at' => 1, 'fuzzy' => true, 'strict_refused' => 'context drifted', 'error' => 'reroll: git timed out',
        ]]);

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertSame('^1', $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertSame([], $this->composer->commands());
        self::assertStringContainsString('#1  no re-roll: reroll: git timed out', $tester->getDisplay());
        self::assertStringContainsString(UpgradeReport::STAYED, $tester->getDisplay());
    }

    // The move rewrites composer.json whole, so an edit a person has not
    // committed would land in the same diff as the move.
    public function testAnUncommittedComposerJsonStopsTheMoveBeforeAnythingIsWritten(): void
    {
        $tester = $this->drive(self::onOne()->edits('composer.json'), self::fuzzy());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json: it has uncommitted changes; commit it or pass --force', $tester->getDisplay());
        self::assertSame('^1', $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertNotSame("rerolled\n", $this->site->read('patches/webform/fix.patch'));
        self::assertSame([], $this->composer->commands());
    }

    // composer's editor matches composer.json with a pattern, and a large
    // file runs that pattern out of backtracking. The move builds the new
    // text before it writes, so the refusal leaves every file as it was.
    public function testAComposerJsonTheEditorRefusesStopsTheMoveBeforeAnythingIsWritten(): void
    {
        $large = [];
        for ($i = 0; $i < 10000; ++$i) {
            $large['key'.$i] = ['a' => \str_repeat('x', 20), 'b' => [1, 2, 3]];
        }
        $tester = $this->drive(self::onOne()->withExtra('large', $large), self::fuzzy());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/composer\.json: \S.* could not be (raised|removed|written)/', $tester->getDisplay());
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertNotSame("rerolled\n", $this->site->read('patches/webform/fix.patch'));
        self::assertSame('^1', $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
        self::assertSame([], $this->composer->commands());
    }

    public function testForceMovesASiteWithAnUncommittedComposerJson(): void
    {
        $this->drive(self::onOne()->edits('composer.json'), self::fuzzy(), ['--force' => true]);

        self::assertSame(Upgrade::CONSTRAINT, $this->json('composer.json')['require'][Manager::PACKAGE] ?? null);
    }

    public function testARenamedSettingCarriesOverAndADroppedOneGoes(): void
    {
        $site = self::onOne()->withExtra('patches-ignore', ['drupal/webform' => ['drupal/token' => ['Skip' => 'x.patch']]])->withExtra('patchLevel', ['drupal/webform' => '-p2']);

        $this->drive($site, self::fuzzy());

        $extra = (array) $this->json('composer.json')['extra'];
        self::assertArrayNotHasKey('patches-ignore', $extra);
        self::assertArrayNotHasKey('patchLevel', $extra);
        self::assertSame(
            ['drupal/webform' => ['drupal/token' => ['Skip' => 'x.patch']]],
            $extra[Settings::KEY]['ignore-dependency-patches'] ?? null
        );
    }

    // 2.x applies at 1 unless the definition or its package says otherwise,
    // so a patch measured at 1 has nothing to record.
    public function testADepthIsWrittenOnlyWhereTheMeasuredLevelDiffers(): void
    {
        $this->drive(self::onOne(), ['verdict' => 'applies', 'result' => ['applies_at' => 1, 'fuzzy' => true, 'reroll' => ['status' => 'clean', 'patch' => "a\n"]]]);
        self::assertArrayNotHasKey('depth', (array) $this->json(UpgradeCommand::PATCHES_FILE)['patches']['drupal/webform'][0]);

        $this->site?->leave();
        $this->server?->stop();
        $this->drive(self::onOne(), ['verdict' => 'applies', 'result' => ['applies_at' => 2, 'fuzzy' => true, 'reroll' => ['status' => 'clean', 'patch' => "a\n"]]]);
        self::assertSame(2, $this->json(UpgradeCommand::PATCHES_FILE)['patches']['drupal/webform'][0]['depth'] ?? null);
    }

    public function testARerollLeavingRegionsOpenStopsBeforeTheRequirementMoves(): void
    {
        $reroll = ['status' => 'conflicts', 'patch' => '', 'conflicts' => [['file' => 'src/A.php', 'regions' => 1, 'hunks' => [['release' => "one\n", 'patch' => "two\n", 'line' => 1]]]]];
        $tester = $this->drive(self::onOne(), ['verdict' => 'conflicts', 'result' => ['reroll' => $reroll]]);

        $json = $this->json('composer.json');
        self::assertSame('^1', $json['require'][Manager::PACKAGE] ?? null);
        self::assertArrayHasKey('patches', (array) $json['extra']);
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertTrue($this->site->has('patches/webform/fix.conflict.patch'));
        self::assertStringContainsString('1 region', $tester->getDisplay());
        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertSame([], $this->composer->commands());
    }

    /** A site whose composer.json an earlier run moved, while its lock still pins 1.x. */
    private static function moved(): SiteFixture
    {
        return self::onOne()->withExtra(Settings::KEY, ['patches-file' => UpgradeCommand::PATCHES_FILE]);
    }

    // The lock still pins 1.x until composer installs the raised
    // requirement, so the second run reads composer.json rather than it.
    public function testASecondRunWritesNothingAndRunsTheManager(): void
    {
        $tester = $this->drive(self::moved(), self::fuzzy());

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        $json = $this->json('composer.json');
        self::assertSame('^1', $json['require'][Manager::PACKAGE] ?? null);
        self::assertArrayHasKey('patches', (array) $json['extra']);
        self::assertFalse($this->site?->has(UpgradeCommand::PATCHES_FILE));
        self::assertSame(['update', ManagerCommands::RELOCK, ManagerCommands::REPATCH], $this->composer->commands());
        self::assertStringContainsString(UpgradeReport::RESUMED, $tester->getDisplay());
        self::assertStringContainsString('moved to cweagans/composer-patches 2.x', $tester->getDisplay());
    }

    public function testASecondRunThatFailsSaysWhatIsLeft(): void
    {
        $this->composer->failing(ManagerCommands::REPATCH);

        $tester = $this->drive(self::moved(), self::fuzzy());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('run `composer patches-repatch` to finish', $tester->getDisplay());
    }

    public function testADryRunOnAMovedSiteNamesTheManagerCommandsAndRunsNone(): void
    {
        $tester = $this->drive(self::moved(), self::fuzzy(), ['--dry-run' => true]);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertSame([], $this->composer->commands());
        self::assertStringContainsString(UpgradeReport::RESUMED."\n  a real run finishes with:", $tester->getDisplay());
    }
}
