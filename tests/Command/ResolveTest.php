<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * `--resolve` driven end to end against a site on disk, because what it
 * prints and what it exits with are the whole of its contract.
 */
#[CoversClass(CheckCommand::class)]
final class ResolveTest extends TestCase
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
     */
    private function drive(SiteFixture $site, array $input, ?array $plan = null): CommandTester
    {
        $this->site = $site;
        $endpoint = 'http://127.0.0.1:1/never-called';
        if (null !== $plan) {
            $this->server = new PlanServer($plan);
            $endpoint = $this->server->endpoint;
        }
        $composer = $site->enter($endpoint);

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

    public function testResolveWithNoEditedFileSaysSoAndWritesNothing(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--resolve' => true]);

        self::assertStringContainsString(CheckCommand::NOTHING_DECIDED, $tester->getDisplay());
        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertFalse($site->has('patches/webform/fix.conflict.patch'));
        self::assertSame("diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n", $site->read('patches/webform/fix.patch'));
    }

    public function testResolveWithAnUntouchedConflictFileSaysSoToo(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch',
            "# drupatch region 0 src/Form.php\n<<<<<<< release src/Form.php:1\na\n=======\nb\n>>>>>>> patch\n# drupatch end 0 src/Form.php\n");

        $tester = $this->drive($site, ['--resolve' => true]);

        self::assertStringContainsString(CheckCommand::NOTHING_DECIDED, $tester->getDisplay());
        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
    }

    public function testDryRunResolvePrintsTheRequestWithTheDecidedRegions(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--resolve' => true, '--dry-run' => true]);

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

        $tester = $this->drive($site, ['--resolve' => true, '--json' => true], self::plan('conflicts', [
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

        $tester = $this->drive($site, ['--resolve' => true], self::plan('conflicts', [
            'status' => 'conflicts',
            'patch' => "part\n",
            'verified' => false,
            'conflicts' => [['file' => 'src/Form.php', 'regions' => 1, 'hunks' => [['line' => 1, 'release' => "a\n", 'patch' => "b\n"]]]],
        ]));

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
    }

    public function testTheExitCodeIsCleanOnceTheResolvedPatchApplies(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--resolve' => true], self::plan('applies', [
            'status' => 'clean',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n",
            'verified' => true,
            'verified_by' => 'git apply --cached --check -p1 against 6.2.9',
            'conflicts' => [],
        ]));

        self::assertSame(Plan::CLEAN, $tester->getStatusCode(), $tester->getDisplay());
    }
}
