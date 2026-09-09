<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\PinCommand;
use TresBienTech\Drupatch\Plan\Plan;

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
     * @param array<string, mixed> $input
     */
    private function drive(array $input, string $source = self::MR, bool $vendored = true): CommandTester
    {
        $this->site = (new SiteFixture())->declares('3521733: bfcache', $source);
        $composer = $this->site->enter('http://127.0.0.1:1');
        if ($vendored) {
            $this->site->write('patch/webform/mr940.diff', "# drupatch {\"mr\":\"https://git.drupalcode.org/project/webform/-/merge_requests/940\"}\ndiff --git a/x b/x\n");
        }

        $command = new PinCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function declared(): array
    {
        return (array) (\json_decode((string) $this->site?->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? []);
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

    public function testTheJsonSaysWhatItDid(): void
    {
        $tester = $this->drive(['--format' => 'json']);

        $out = (array) \json_decode($tester->getDisplay(), true);
        self::assertSame([], $out['vendored']);
        self::assertSame('patch/webform/mr940.diff', $out['kept'][0]['path']);
        self::assertSame([], $out['refused']);
    }
}
