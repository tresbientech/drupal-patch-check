<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\PatchConfig;

/**
 * A site set up the way 2.x of the patch manager recommends: the
 * declarations in a patches file, in the expanded object form.
 */
#[CoversClass(PatchConfig::class)]
class TwoLineSiteTest extends TestCase
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

    /** What the service says about a patch only its lenient apply took. */
    private const FUZZY = ['verdict' => 'applies', 'result' => ['applies_at' => 1, 'fuzzy' => true, 'strict_refused' => 'context drifted, your patch manager still applies it']];

    /**
     * A check run against a service that answers one row about the one declared patch.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $input
     */
    private function check(SiteFixture $site, array $row, array $input = []): CommandTester
    {
        $this->site = $site;
        $this->server = new PlanServer(['target_core' => '', 'counts' => [], 'rows' => [], 'plan' => [
            'counts' => ['applies' => 1],
            'patches' => [['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => ''] + $row],
        ]]);

        $command = new CheckCommand();
        $command->setComposer($site->enter($this->server->endpoint));
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function onTwo(): SiteFixture
    {
        return (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('2.0.0')->inPatchesFile('patches.json');
    }

    // 2.x applies with git apply alone, so the patch the service says still
    // applies is the one the next install stops on.
    public function testAPatchOnlyALenientApplyTookIsWorkOn2x(): void
    {
        $tester = $this->check(self::onTwo(), self::FUZZY);

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('your patch manager refuses it; `composer drupatch:reroll` writes a version it applies', $tester->getDisplay());
        self::assertStringNotContainsString('still applies it', $tester->getDisplay());
        self::assertStringContainsString('1 refused by 2.x', $tester->getDisplay());
    }

    public function testThePatchStillAppliesOn1x(): void
    {
        $tester = $this->check((new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('1.7.3'), self::FUZZY);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertStringContainsString('context drifted, your patch manager still applies it', $tester->getDisplay());
    }

    // A job reading the JSON gets the service's answer, and reads `fuzzy`
    // against the manager it knows the site runs.
    public function testTheJsonKeepsTheServicesAnswer(): void
    {
        $tester = $this->check(self::onTwo(), self::FUZZY, ['--format' => 'json']);

        $row = ((array) \json_decode($tester->getDisplay(), true))['plan']['patches'][0]['result'] ?? [];
        self::assertSame('context drifted, your patch manager still applies it', $row['strict_refused'] ?? null);
        self::assertArrayNotHasKey('failure_mode', $row);
    }

    /**
     * The request the site would send, read out of a `--dry-run`.
     *
     * @return array<string, mixed>
     */
    private function body(SiteFixture $site): array
    {
        $this->site = $site;
        $composer = $site->enter('http://127.0.0.1:1/v1/composer/scan');

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true], ['capture_stderr_separately' => true]);

        return (array) \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testAPatchesFileIsJudgedLikeAnInlineDeclaration(): void
    {
        $body = $this->body((new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            ->withManager('2.0.0')
            ->inPatchesFile('patches.json'));

        self::assertSame([['package' => 'drupal/webform', 'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n"]], $body['patch_config']);
    }

    public function testTheExpandedFormIsJudgedLikeTheCompactOne(): void
    {
        $body = $this->body((new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            ->withManager('2.0.0')
            ->inPatchesFile('patches.json')
            ->expanded());

        self::assertSame([['package' => 'drupal/webform', 'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n"]], $body['patch_config']);
    }

    // The declarations are the site's, and none of them travels.
    public function testTheFileAndTheShapeStayHome(): void
    {
        $body = $this->body((new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            ->withManager('2.0.0')
            ->inPatchesFile('patches.json')
            ->expanded());

        $encoded = (string) \json_encode($body);
        self::assertStringNotContainsString('patches.json', $encoded);
        self::assertStringNotContainsString('expanded', $encoded);
    }
}
