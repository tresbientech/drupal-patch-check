<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Closure;
use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\PinCommand;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Tests\StubHost;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What a pin run does to a site's declarations, without reaching any host:
 * the copy is already in the site, so only the declaration is left to fix.
 */
#[CoversClass(PinCommand::class)]
class PinCommandTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.patch';

    private ?SiteFixture $site = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->site = null;
    }

    /**
     * @param array<string, mixed>                   $input
     * @param ?Closure(SiteFixture): void            $edit  run on the site once it is committed, before the command
     * @param array<string, array{int, string}>|null $hosts what drupalcode answers, null for a run that reaches no host
     */
    private function drive(array $input, string $source = self::MR, bool $vendored = true, string $manager = '', ?Closure $edit = null, ?array $hosts = null, bool $inGit = true): CommandTester
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', $source)->withManager($manager);
        if ($inGit) {
            $this->site->inGit();
        }
        $composer = $this->site->enter('http://127.0.0.1:1');
        if (null !== $edit) {
            $edit($this->site);
        }
        if ($vendored) {
            $this->site->write('patch/webform/mr940.diff', "# drupatch {\"mr\":\"https://git.drupalcode.org/project/webform/-/merge_requests/940\"}\ndiff --git a/x b/x\n");
        }

        $command = null === $hosts ? new PinCommand() : new class($hosts) extends PinCommand {
            /**
             * @param array<string, array{int, string}> $hosts
             */
            public function __construct(private readonly array $hosts)
            {
                parent::__construct();
            }

            protected function patchText(string $root): PatchText
            {
                return new PatchText($root, StubHost::fetch($this->hosts), '');
            }
        };
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * The package's declarations, a title-keyed map or a list in the expanded shape.
     *
     * @return array<int|string, mixed>
     */
    private function declared(): array
    {
        return (array) (\json_decode((string) $this->site?->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? []);
    }

    /**
     * What drupalcode answers for merge request 940 and its diff.
     *
     * @return array<string, array{int, string}>
     */
    private static function drupalcode(): array
    {
        return [
            'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940' => [200, '{"sha":"bbb","diff_refs":{"base_sha":"aaa","head_sha":"bbb","start_sha":"aaa"}}'],
            'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff' => [200, "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n"],
        ];
    }

    // Git is asked before the copy, so a refusal leaves no copy behind.
    public function testAnEditedPatchConfigStopsThePinBeforeAnyCopy(): void
    {
        $tester = $this->drive([], vendored: false, hosts: self::drupalcode(), edit: static function (SiteFixture $site): void {
            $decoded = (array) \json_decode($site->read('composer.json'), true);
            $decoded['extra']['patches']['drupal/webform']['Local'] = 'patches/local.patch';
            $site->write('composer.json', (string) \json_encode($decoded, \JSON_PRETTY_PRINT));
        });

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json has uncommitted changes to its patches; commit them or pass --force', $tester->getDisplay());
        self::assertFalse($this->site?->has('patch/webform/mr940.diff'), 'nothing was copied');
    }

    public function testASiteGitCannotReadIsRefusedLikeTheOtherWrites(): void
    {
        $tester = $this->drive([], vendored: false, hosts: self::drupalcode(), inGit: false);

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json: '.WorkingTree::NOT_A_CHECKOUT.'; pass --force', $tester->getDisplay());
        self::assertFalse($this->site?->has('patch/webform/mr940.diff'), 'nothing was copied');
    }

    public function testADryRunAsksGitNothing(): void
    {
        $tester = $this->drive(['--dry-run' => true], inGit: false);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertStringNotContainsString(WorkingTree::NOT_A_CHECKOUT, $tester->getDisplay());
    }

    public function testTheDeclarationNamesTheFileTheSiteHolds(): void
    {
        $tester = $this->drive([]);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertSame(['3521733: bfcache' => 'patch/webform/mr940.diff'], $this->declared());
        $display = $tester->getDisplay();
        self::assertStringContainsString('already in the site:', $display);
        self::assertStringContainsString('      patch/webform/mr940.diff', $display);
        self::assertStringContainsString('composer.json: 1 declaration now names a file in the site', $display);
    }

    // 2.x keeps the record on the definition, so the line older releases
    // wrote at the top of the copy moves there and the file holds the diff alone.
    public function testOnTwoTheRecordMovesOntoTheDeclaration(): void
    {
        $tester = $this->drive([], manager: '2.0.0');

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertSame([[
            'description' => '3521733: bfcache',
            'url' => 'patch/webform/mr940.diff',
            'extra' => ['drupatch' => ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940']],
        ]], $this->declared());
        self::assertSame("diff --git a/x b/x\n", $this->site?->read('patch/webform/mr940.diff'));
    }

    public function testALocalDeclarationIsLeftWhereItIs(): void
    {
        $tester = $this->drive([], 'patches/webform/fix.patch', false);

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertSame(['3521733: bfcache' => 'patches/webform/fix.patch'], $this->declared());
        self::assertStringContainsString('no patch is declared from a merge request', $tester->getDisplay());
    }

    public function testADryRunLeavesTheDeclarationAlone(): void
    {
        $tester = $this->drive(['--dry-run' => true]);

        self::assertSame(['3521733: bfcache' => self::MR], $this->declared());
        self::assertStringContainsString('patch/webform/mr940.diff', $tester->getDisplay());
    }

    public function testAPackageItWasNotAskedForIsUntouched(): void
    {
        $this->drive(['--package' => ['drupal/token']]);

        self::assertSame(['3521733: bfcache' => self::MR], $this->declared());
    }

    // Every declaration names a file in the site now, so there is nothing
    // left for somebody to push to.
    public function testARunThatRepointedEveryDeclarationWarnsAboutNone(): void
    {
        $tester = $this->drive([]);

        self::assertStringNotContainsString('merge request URL', $tester->getDisplay());
    }

    public function testADryRunSaysTheDeclarationsStillNameTheRequest(): void
    {
        $tester = $this->drive(['--dry-run' => true]);

        self::assertStringContainsString('1 patch is declared as a merge request URL', $tester->getDisplay());
    }

    public function testTheWarningGoesToStderrWhenStdoutCarriesTheDocument(): void
    {
        $tester = $this->drive(['--dry-run' => true, '--format' => 'json']);

        self::assertIsArray(\json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('1 patch is declared as a merge request URL', $tester->getErrorOutput());
    }

    public function testTheJsonSaysWhatItDid(): void
    {
        $tester = $this->drive(['--format' => 'json']);

        $out = (array) \json_decode($tester->getDisplay(), true);
        self::assertSame([], $out['vendored']);
        self::assertSame('patch/webform/mr940.diff', $out['kept'][0]['path']);
        self::assertSame([], $out['refused']);
    }
}
