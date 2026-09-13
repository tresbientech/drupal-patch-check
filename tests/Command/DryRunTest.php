<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use Composer\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\CheckCommand;
use TresBienTech\Drupatch\Command\PatchCommand;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Tests\StubHost;

/**
 * What a check or re-roll `--dry-run` puts on each stream. It asks the
 * service nothing and prints the body a real run posts.
 */
class DryRunTest extends TestCase
{
    private const URL = 'https://example.test/files/fix.patch';

    private const DIFF = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+stub\n";

    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->site = null;
        $this->server = null;
    }

    // A reviewer approves the request by reading the dry run.
    public function testACheckDryRunPrintsTheBodyARealRunPosts(): void
    {
        [$printed, $posted] = $this->printedThenPosted(new CheckCommand(), new SiteFixture(), []);

        self::assertSame($posted, $printed);
    }

    public function testAReRollDryRunPrintsTheBodyARealRunPosts(): void
    {
        $site = (new SiteFixture())->write('patches/webform/fix.conflict.patch', "# drupatch: 1 unresolved region(s) in src/Form.php\n# drupatch region 0 src/Form.php\n  \$decided = TRUE;\n# drupatch end 0 src/Form.php\n");

        // The site is no checkout, and --force asks git nothing.
        [$printed, $posted] = $this->printedThenPosted(new RerollCommand(), $site, ['--force' => true]);

        self::assertSame($posted, $printed);
        self::assertArrayHasKey('resolutions', $posted['patch_config'][0] ?? []);
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

        self::assertStringContainsString('private-paths is no longer read', $tester->getErrorOutput());
    }

    // A test hands a run its site through site(), so the text of a patch
    // declared by URL comes from the stub host alone.
    public function testACheckReadsTheSiteItsCommandGives(): void
    {
        $command = new class([self::URL => [200, self::DIFF]]) extends CheckCommand {
            /**
             * @param array<string, array{int, string}> $hosts
             */
            public function __construct(private readonly array $hosts)
            {
                parent::__construct();
            }

            protected function site(): Site
            {
                return Site::at($this->requireComposer(), $this->getIO(), Factory::getComposerFile(), StubHost::fetch($this->hosts));
            }
        };

        self::assertSame(self::DIFF, $this->sentText($command));
    }

    public function testAReRollReadsTheSiteItsCommandGives(): void
    {
        $command = new class([self::URL => [200, self::DIFF]]) extends RerollCommand {
            /**
             * @param array<string, array{int, string}> $hosts
             */
            public function __construct(private readonly array $hosts)
            {
                parent::__construct();
            }

            protected function site(): Site
            {
                return Site::at($this->requireComposer(), $this->getIO(), Factory::getComposerFile(), StubHost::fetch($this->hosts));
            }
        };

        self::assertSame(self::DIFF, $this->sentText($command));
    }

    private function drive(): CommandTester
    {
        $this->site = (new SiteFixture())
            ->declaresPatch('Fix', 'patches/webform/fix.patch')
            // Any note will do; this one needs nothing of the service.
            ->withExtra('drupal-patch-check', ['private-paths' => true]);
        $composer = $this->site->enter('http://127.0.0.1:1/v1/composer/scan');

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * The text a dry run's request holds for the one patch the site declares by URL, or what the run printed on stderr when it holds none.
     */
    private function sentText(PatchCommand $command): mixed
    {
        $this->site = (new SiteFixture())->declares('Fix', self::URL);
        $command->setComposer($this->site->enter('http://127.0.0.1:1/v1/composer/scan'));
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true], ['capture_stderr_separately' => true]);
        $body = (array) \json_decode($tester->getDisplay(), true);

        return $body['patch_config'][0]['patch'] ?? $tester->getErrorOutput();
    }

    /**
     * The body a dry run prints, then the body a real run on the same site posts.
     *
     * @param array<string, bool> $input
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function printedThenPosted(PatchCommand $command, SiteFixture $site, array $input): array
    {
        $this->site = $site->declaresPatch('Fix', 'patches/webform/fix.patch');
        $this->server = $server = new PlanServer(['target_core' => '', 'core_installed' => '10.6.9', 'counts' => [], 'rows' => [], 'plan' => ['counts' => [], 'patches' => [
            ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'applies', 'result' => []],
        ]]]);
        $command->setComposer($site->enter($server->endpoint));
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true] + $input, ['capture_stderr_separately' => true]);
        $printed = (array) \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $server->request(), 'the dry run asked the service');

        $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$printed, $server->request()];
    }
}
