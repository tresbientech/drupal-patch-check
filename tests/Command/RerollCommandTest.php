<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\RerollCommand;
use TresBienTech\Drupatch\Write\PatchFiles;

/**
 * The re-roll driven end to end against a site on disk, because what it
 * prints, what it writes and what it exits with are the whole of its
 * contract. Every run reads the conflict files it finds; none of these
 * pass a flag to make it.
 */
#[CoversClass(RerollCommand::class)]
class RerollCommandTest extends TestCase
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
     * @param array<string, mixed>|null $plan
     * @param array<string, mixed>      $input
     * @param list<string>              $stdin
     */
    private function drive(SiteFixture $site, array $input, ?array $plan = null, array $stdin = []): CommandTester
    {
        $this->site = $site;
        $endpoint = 'http://127.0.0.1:1/never-called';
        if (null !== $plan) {
            $this->server = new PlanServer($plan);
            $endpoint = $this->server->endpoint;
        }
        $composer = $site->enter($endpoint);

        $command = new RerollCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        if ([] !== $stdin) {
            $tester->setInputs($stdin);
        }
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(string $verdict, mixed $reroll): array
    {
        return [
            'target_core' => '',
            'core_installed' => '10.6.9',
            'counts' => [],
            'rows' => [],
            'plan' => [
                'counts' => [],
                'patches' => [[
                    'package' => 'drupal/webform',
                    'project' => 'webform',
                    'version' => '6.2.9',
                    'title' => 'Fix',
                    'source' => 'patches/webform/fix.patch',
                    'verdict' => $verdict,
                    'result' => null === $reroll ? [] : ['reroll' => $reroll],
                ]],
            ],
        ];
    }

    /**
     * One value out of the JSON object in a display that also carries
     * comment lines, addressed by the keys leading to it.
     */
    private static function at(string $display, int|string ...$path): mixed
    {
        $start = \strpos($display, '{');
        $value = false === $start ? null : \json_decode(\substr($display, $start), true);
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function decided(): string
    {
        return "# drupatch: 1 unresolved region(s) in src/Form.php\n"
            ."# drupatch region 0 src/Form.php\n"
            ."  \$decided = TRUE;\n"
            ."# drupatch end 0 src/Form.php\n";
    }

    private static function undecided(): string
    {
        return "# drupatch region 0 src/Form.php\n<<<<<<< release src/Form.php:1\na\n=======\nb\n>>>>>>> patch\n# drupatch end 0 src/Form.php\n";
    }

    public function testWithNoConflictFileTheRequestCarriesNoDecision(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--dry-run' => true]);

        self::assertTrue(self::at($tester->getDisplay(), 'reroll'));
        self::assertNull(self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions'));
        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
    }

    public function testAnUntouchedConflictFileDecidesNothing(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::undecided());

        $tester = $this->drive($site, ['--dry-run' => true]);

        self::assertNull(self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions'));
    }

    public function testAnEditedConflictFileIsSentWithoutAFlag(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--dry-run' => true]);

        self::assertSame(
            [['file' => 'src/Form.php', 'region' => 0, 'text' => '  $decided = TRUE;']],
            self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions')
        );
        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
    }

    public function testJsonCarriesTheRegionsTheServiceLeftOpen(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--json' => true], self::plan('conflicts', [
            'status' => 'conflicts',
            'patch' => "part\n",
            'verified' => false,
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
            'resolutions_applied' => 0,
            'resolutions_missing' => [['file' => 'src/Form.php', 'region' => 0]],
        ]));

        self::assertSame(
            [['file' => 'src/Form.php', 'region' => 0]],
            self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'resolutions_missing')
        );
    }

    public function testJsonNamesTheCommandToRunNext(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--force' => true, '--json' => true], self::plan('conflicts', [
            'status' => 'conflicts',
            'patch' => "part\n",
            'verified' => false,
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]));

        self::assertSame(Report::REROLL, self::at($tester->getDisplay(), 'summary', 'next', 0, 'command'));
        self::assertSame('', self::at($tester->getDisplay(), 'summary', 'next', 0, 'flag'));
    }

    public function testJsonKeepsStdoutPureWhileNotesGoToStderr(): void
    {
        $site = (new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            ->withExtra('patches-search', true);

        $tester = $this->drive($site, ['--json' => true], self::plan('applies', null));

        $display = $tester->getDisplay();
        self::assertIsArray(\json_decode($display, true), $display);
        self::assertStringContainsString('patches-search', $tester->getErrorOutput());
    }

    public function testTheExitCodeFailsWhileAPatchStillDoesNotApply(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, [], self::plan('conflicts', [
            'status' => 'conflicts',
            'patch' => "part\n",
            'verified' => false,
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]));

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
    }

    public function testACleanRerollWritesThePatchAndRemovesTheConflictFile(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--force' => true], self::plan('applies', [
            'status' => 'clean',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n",
            'verified' => true,
            'verified_by' => 'git apply --cached --check -p1 against 6.2.9',
            'conflicts' => [],
        ]));

        self::assertSame(Plan::CLEAN, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('+resolved', $site->read('patches/webform/fix.patch'));
        self::assertFalse($site->has('patches/webform/fix.conflict.patch'));
    }

    public function testAServerCannotChooseTheWriteTarget(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $plan = self::plan('conflicts', ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true]);
        $plan['plan']['patches'][0]['source'] = 'web/sites/default/settings.php';

        // --force drops the working-tree guard, which would otherwise
        // refuse the write because the fixture site is not a git checkout.
        $this->drive($site, ['--force' => true], $plan);

        self::assertFalse($site->has('web/sites/default/settings.php'));
        self::assertSame("new diff\n", $site->read('patches/webform/fix.patch'));
    }

    public function testARowNamingAPatchTheSiteNeverDeclaredIsRefused(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $plan = self::plan('conflicts', ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true]);
        $plan['plan']['patches'][0]['title'] = 'Not what the site declared';
        $plan['plan']['patches'][0]['source'] = 'web/sites/default/settings.php';

        $tester = $this->drive($site, [], $plan);

        self::assertFalse($site->has('web/sites/default/settings.php'));
        self::assertStringContainsString(PatchFiles::NOT_DECLARED, $tester->getDisplay());
    }

    private static function document(string $choice = 'release'): string
    {
        return (string) \json_encode(['decisions' => [['source' => 'patches/webform/fix.patch', 'file' => 'src/Form.php', 'region' => 0, 'choice' => $choice]]]);
    }

    public function testADecisionsFileIsSentAsResolutions(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('decisions.json', self::document('patch'));

        $tester = $this->drive($site, ['--decisions' => $site->root().'/decisions.json', '--dry-run' => true]);

        self::assertSame(
            [['file' => 'src/Form.php', 'region' => 0, 'choice' => 'patch']],
            self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions')
        );
    }

    public function testTheDocumentComesFromStdinForADash(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--decisions' => '-', '--dry-run' => true], null, [self::document()]);

        self::assertSame(
            [['file' => 'src/Form.php', 'region' => 0, 'choice' => 'release']],
            self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions')
        );
    }

    public function testTheDocumentOverridesTheConflictFileAndSaysWhich(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());
        $site->write('decisions.json', self::document());

        $tester = $this->drive($site, ['--decisions' => $site->root().'/decisions.json', '--dry-run' => true]);

        self::assertSame(
            [['file' => 'src/Form.php', 'region' => 0, 'choice' => 'release']],
            self::at($tester->getDisplay(), 'patch_config', 0, 'resolutions')
        );
        self::assertStringContainsString('the document decides src/Form.php region 0 of patches/webform/fix.patch, over its conflict file', $tester->getErrorOutput());
    }

    public function testADecisionTheServiceFoundNoRegionForStopsTheRunBeforeAnyWrite(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('decisions.json', self::document());

        // The stub merges cleanly and reports no resolution applied: the
        // region the document named is not in conflict on this release.
        $tester = $this->drive($site, ['--decisions' => $site->root().'/decisions.json', '--force' => true], self::plan('applies', [
            'status' => 'clean',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n",
            'verified' => true,
            'conflicts' => [],
        ]));

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('1 of the 1 decisions sent for patches/webform/fix.patch named no conflicted region, so they decided nothing; nothing was written', $tester->getDisplay());
        self::assertStringNotContainsString('+resolved', $site->read('patches/webform/fix.patch'));
    }

    public function testAFileTheReleaseRemovedDoesNotRefuseTheDocumentThatDecidesTheRest(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('decisions.json', self::document());

        // One region decided and applied, beside a file the release
        // removed. The removed file asks nothing, so the count the guard
        // compares against is the one region.
        $tester = $this->drive($site, ['--decisions' => $site->root().'/decisions.json', '--force' => true], self::plan('conflicts', [
            'status' => 'conflicts',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n",
            'conflicts' => [
                ['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]],
                ['file' => 'src/Gone.php', 'regions' => 1, 'removed' => true, 'hunks' => [['line' => 0, 'release' => "file does not exist in the release\n", 'patch' => "-old\n"]]],
            ],
            'resolutions_applied' => 1,
        ]));

        self::assertStringNotContainsString('named no conflicted region', $tester->getDisplay());
        $written = $site->read('patches/webform/fix.conflict.patch');
        self::assertStringContainsString('+resolved', $written);
        self::assertStringContainsString('src/Gone.php is not in the release', $written);
    }

    public function testADecisionTheServiceAppliedIsWritten(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('decisions.json', self::document());

        $tester = $this->drive($site, ['--decisions' => $site->root().'/decisions.json', '--force' => true], self::plan('applies', [
            'status' => 'clean',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n",
            'verified' => true,
            'conflicts' => [],
            'resolutions_applied' => 1,
        ]));

        self::assertSame(Plan::CLEAN, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('+resolved', $site->read('patches/webform/fix.patch'));
    }

    public function testTheJsonNamesTheFileWrittenInPlaceOfTheDiff(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $diff = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n";

        $tester = $this->drive($site, ['--force' => true, '--json' => true], self::plan('applies', ['status' => 'clean', 'patch' => $diff, 'verified' => true, 'conflicts' => []]));

        self::assertSame('', self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'patch'));
        self::assertSame('patches/webform/fix.patch', self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'path'));
        self::assertSame($diff, $site->read('patches/webform/fix.patch'));
        self::assertSame([['path' => 'patches/webform/fix.patch', 'status' => 'clean']], self::at($tester->getDisplay(), 'written'));
    }

    public function testTheJsonKeepsTheDiffOfARowItRefused(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $diff = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n";

        // Not a git checkout and no --force: the write is refused.
        $tester = $this->drive($site, ['--json' => true], self::plan('applies', ['status' => 'clean', 'patch' => $diff, 'verified' => true, 'conflicts' => []]));

        self::assertSame($diff, self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'patch'));
        self::assertNull(self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'path'));
        self::assertCount(1, (array) self::at($tester->getDisplay(), 'refused'));
    }
}
