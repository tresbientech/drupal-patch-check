<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Closure;
use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\StubHost;
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
    private const SEARCH = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests?state=all&per_page=50&search=3521733';

    private const REQUEST = 'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940';

    private const FORK = 'https://git.drupalcode.org/api/v4/projects/243137';

    private const COMPARE = 'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff';

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
     * @param array<string, mixed>|null              $plan
     * @param array<string, mixed>                   $input
     * @param list<string>                           $stdin
     * @param array<string, array{int, string}>|null $hosts what drupalcode answers, null for a run that reaches no host
     * @param ?Closure(SiteFixture): void            $edit  run on the site once it is committed, before the command
     */
    private function drive(SiteFixture $site, array $input, ?array $plan = null, array $stdin = [], ?array $hosts = null, ?Closure $edit = null): CommandTester
    {
        $this->site = $site;
        $endpoint = 'http://127.0.0.1:1/never-called';
        if (null !== $plan) {
            $this->server = new PlanServer($plan);
            $endpoint = $this->server->endpoint;
        }
        $composer = $site->enter($endpoint);
        if (null !== $edit) {
            $edit($site);
        }

        $command = null === $hosts ? new RerollCommand() : new class($hosts) extends RerollCommand {
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
                // The service answers no title and an empty source, since
                // the request carries neither; the run puts its own back.
                'patches' => [[
                    'package' => 'drupal/webform',
                    'project' => 'webform',
                    'version' => '6.2.9',
                    'source' => '',
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
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->inGit();
        $site->write('patches/webform/fix.conflict.patch', self::decided());

        $tester = $this->drive($site, ['--format' => 'json'], self::plan('conflicts', [
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

        $tester = $this->drive($site, ['--force' => true, '--format' => 'json'], self::plan('conflicts', [
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
            ->withExtra('drupal-patch-check', ['private-paths' => true])
            ->inGit();

        $tester = $this->drive($site, ['--format' => 'json'], self::plan('applies', null));

        $display = $tester->getDisplay();
        self::assertIsArray(\json_decode($display, true), $display);
        self::assertStringContainsString('private-paths', $tester->getErrorOutput());
    }

    public function testTheExitCodeFailsWhileAPatchStillDoesNotApply(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->inGit();
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

    /**
     * @return iterable<string, array{array<string, bool>, bool|null}>
     */
    public static function flagsAndWhatTheySend(): iterable
    {
        yield 'drop' => [['--drop-tests' => true], true];
        yield 'keep' => [['--keep-tests' => true], false];
        yield 'neither' => [[], null];
    }

    // The request says something about test files only when the run did,
    // so a run naming neither flag leaves the choice to the service.
    /**
     * @param array<string, bool> $flags
     */
    #[DataProvider('flagsAndWhatTheySend')]
    public function testTheRequestCarriesTheTestFilesChoiceTheRunNamed(array $flags, ?bool $sent): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--dry-run' => true] + $flags);

        $display = $tester->getDisplay();
        $body = (array) \json_decode(\substr($display, (int) \strpos($display, '{')), true);
        self::assertSame(null !== $sent, \array_key_exists('drop_tests', $body));
        self::assertSame($sent, $body['drop_tests'] ?? null);
    }

    /**
     * A clean re-roll that left two test files out.
     *
     * @return array<string, mixed>
     */
    private static function withoutItsTests(): array
    {
        return self::plan('conflicts', [
            'status' => 'clean',
            'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n",
            'verified' => true,
            'dropped_tests' => ['tests/src/Unit/ATest.php', 'tests/src/Unit/BTest.php'],
        ]);
    }

    // The service left the test files out on its own, so the run says how
    // to get them back.
    public function testAReRollThatLeftTestsOutSaysSoUnderTheFileItWrote(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--force' => true], self::withoutItsTests());

        self::assertStringContainsString(Report::droppedTestsNote(2).'; a re-roll with --keep-tests keeps them', $tester->getDisplay());
    }

    // A run that asked for the drop needs no pointer back.
    public function testARunThatAskedForTheDropGetsNoPointerBack(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');

        $tester = $this->drive($site, ['--force' => true, '--drop-tests' => true], self::withoutItsTests());

        self::assertStringContainsString(Report::droppedTestsNote(2), $tester->getDisplay());
        self::assertStringNotContainsString('--keep-tests', $tester->getDisplay());
    }

    // A conflicting patch titled with an issue number is resolved to that
    // issue's merge request. The gates decide before any host is asked; the
    // resolve itself runs against the drupalcode answers below.
    /**
     * @return array<string, mixed>
     */
    private static function conflicting(): array
    {
        return self::plan('conflicts', ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true]);
    }

    /**
     * What drupalcode answers to resolve issue 3521733 to merge request 940, and that request's diff.
     *
     * @return array<string, array{int, string}>
     */
    private static function drupalcode(): array
    {
        return [
            self::SEARCH => [200, (string) \json_encode([['iid' => 940, 'title' => 'fix: #3521733', 'target_branch' => '6.2.x', 'draft' => false, 'state' => 'opened', 'updated_at' => '2026-09-09T00:00:00Z', 'source_project_id' => 243137]])],
            self::REQUEST => [200, (string) \json_encode(['source_project_id' => 243137, 'title' => 'Fix the alter hook', 'sha' => 'bbb', 'diff_refs' => ['base_sha' => 'aaa', 'head_sha' => 'bbb', 'start_sha' => 'aaa']])],
            self::FORK => [200, (string) \json_encode(['path_with_namespace' => 'issue/webform-3521733'])],
            self::COMPARE => [200, "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+upstream\n"],
        ];
    }

    // A run that resolved a title rewrote composer.json, and its second
    // plan reads the declarations from that file as it now stands.
    public function testAResolvedTitleIsReRolledOverTheCopyItsRunMade(): void
    {
        $site = (new SiteFixture())->declaresPatch('3521733: Fix the alter hook', 'patches/webform/fix.patch')->withManager('2.0.0')->inGit();

        $tester = $this->drive($site, [], self::conflicting(), hosts: self::drupalcode());

        self::assertSame("new diff\n", $site->has('patch/webform/mr940.diff') ? $site->read('patch/webform/mr940.diff') : null, $tester->getDisplay());
    }

    // A clean re-roll lands over the copy the same run made, which no
    // commit holds: the run wrote it, so no refusal protects it.
    public function testAResolvedTitleInAPatchesFileIsReRolledOverTheCopyItsRunMade(): void
    {
        $site = (new SiteFixture())->declaresPatch('3521733: Fix the alter hook', 'patches/webform/fix.patch')->withManager('2.0.0')->inPatchesFile('patches.json')->inGit();

        $tester = $this->drive($site, [], self::conflicting(), hosts: self::drupalcode());

        self::assertSame("new diff\n", $site->has('patch/webform/mr940.diff') ? $site->read('patch/webform/mr940.diff') : null, $tester->getDisplay());
    }

    /**
     * Points one composer.json declaration elsewhere, so its patches differ from the last commit.
     *
     * @return Closure(SiteFixture): void
     */
    private static function repoints(string $title, string $source): Closure
    {
        return static function (SiteFixture $site) use ($title, $source): void {
            $decoded = (array) \json_decode($site->read('composer.json'), true);
            $decoded['extra']['patches']['drupal/webform'][$title] = $source;
            $site->write('composer.json', (string) \json_encode($decoded, \JSON_PRETTY_PRINT));
        };
    }

    // Every document in scope is asked about before the service, so a
    // refusal costs no re-roll and leaves every file as it was.
    public function testAnEditedPatchConfigStopsTheRunBeforeTheServiceIsAsked(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('2.0.0')->inGit();

        $tester = $this->drive($site, [], self::conflicting(), edit: self::repoints('Local', 'patches/webform/local.patch'));

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json has uncommitted changes to its patches; commit them or pass --force', $tester->getDisplay());
        self::assertStringNotContainsString('patches: ', $tester->getDisplay(), 'no report');
        self::assertSame([], $this->server?->request(), 'the service was not asked');
        self::assertNotSame("new diff\n", $site->read('patches/webform/fix.patch'));
    }

    public function testAnEditOutsideThePatchConfigDoesNotStopTheRun(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('2.0.0')->inGit()->edits('composer.json');

        $tester = $this->drive($site, [], self::conflicting());

        self::assertStringNotContainsString('uncommitted', $tester->getDisplay());
        self::assertSame("new diff\n", $site->read('patches/webform/fix.patch'));
    }

    // The patches file holds the patch the run is scoped to; composer.json
    // holds another, and its edit is none of this run's business.
    public function testAScopedRunIgnoresAnEditedDocumentOutsideItsScope(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('2.0.0')->inPatchesFile('patches.json')
            ->withExtra('patches', ['drupal/webform' => ['Other' => 'patches/webform/other.patch']])->inGit();
        $site->write('patches/webform/other.patch', "diff --git a/y b/y\n--- a/y\n+++ b/y\n@@ -1 +1 @@\n-a\n+b\n");

        $tester = $this->drive($site, ['--patch' => ['patches/webform/fix.patch']], self::conflicting(), edit: self::repoints('Other', 'patches/webform/moved.patch'));

        self::assertNotSame(Plan::FAILED, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('uncommitted', $tester->getDisplay());
    }

    public function testADryRunAsksNothingAboutAnEditedPatchConfig(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager('2.0.0')->inGit();

        $tester = $this->drive($site, ['--dry-run' => true], edit: self::repoints('Local', 'patches/webform/local.patch'));

        self::assertSame(Plan::CLEAN, $tester->getStatusCode());
        self::assertStringNotContainsString('uncommitted', $tester->getDisplay());
        self::assertIsArray(self::at($tester->getDisplay(), 'patch_config'));
    }

    // Resolving the title rewrites composer.json once, and dropping the
    // shipped patch rewrites it again: both are this run's own edits.
    public function testAResolvedTitleAndAShippedPatchWriteBothRewrites(): void
    {
        $site = (new SiteFixture())->declaresPatch('3521733: Fix the alter hook', 'patches/webform/fix.patch')
            ->declaresPatch('Shipped', 'patches/webform/shipped.patch')->withManager('2.0.0')->inGit();
        $plan = self::conflicting();
        $plan['plan']['patches'][] = ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'merged', 'result' => []];

        $tester = $this->drive($site, [], $plan, hosts: self::drupalcode());

        self::assertNotSame(Plan::FAILED, $tester->getStatusCode(), $tester->getDisplay());
        $declared = (array) (\json_decode($site->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? []);
        $sources = \array_map(static fn (mixed $entry): mixed => \is_array($entry) ? ($entry['url'] ?? null) : $entry, $declared);
        self::assertSame(['patch/webform/mr940.diff'], \array_values($sources), 'repointed, and the shipped patch is gone');
    }

    public function testAnIssueTitleWithAMergeRequestThatIsNoNumberIsRefusedBeforeAnyHost(): void
    {
        $site = (new SiteFixture())->declaresPatch('3218426: dblog cap', 'patches/webform/fix.patch')->withManager('2.0.0');

        $tester = $this->drive($site, ['--force' => true, '--mr' => 'abc'], self::conflicting());

        self::assertStringContainsString('3218426: dblog cap stays as declared', $tester->getDisplay());
        self::assertStringContainsString('--mr takes a merge request number', $tester->getDisplay());
        self::assertSame("new diff\n", $site->read('patches/webform/fix.patch'));
    }

    public function testATitleNamingNoIssueIsNeverResolved(): void
    {
        $site = (new SiteFixture())->declaresPatch('dblog cap', 'patches/webform/fix.patch')->withManager('2.0.0');

        $tester = $this->drive($site, ['--force' => true, '--mr' => 'abc'], self::conflicting());

        self::assertStringNotContainsString('stays as declared', $tester->getDisplay());
    }

    // 1.x keeps the source alone, so a copy made here would carry no base.
    public function testASiteOnOneIsNeverResolved(): void
    {
        $site = (new SiteFixture())->declaresPatch('3218426: dblog cap', 'patches/webform/fix.patch')->withManager('1.7.3');

        $tester = $this->drive($site, ['--force' => true, '--mr' => 'abc'], self::conflicting());

        self::assertStringNotContainsString('stays as declared', $tester->getDisplay());
    }

    // A patch that already records where its bytes came from has its merge
    // request, so a second run asks nobody about it again.
    public function testADeclarationThatAlreadyRecordsItsSourceIsNeverResolved(): void
    {
        $site = (new SiteFixture())
            ->declaresPinned('3218426: dblog cap', 'patch/webform/mr940.diff', 'https://git.drupalcode.org/project/webform/-/merge_requests/940', \str_repeat('a', 40))
            ->withManager('2.0.0');

        $tester = $this->drive($site, ['--force' => true, '--mr' => 'abc'], self::conflicting());

        self::assertStringNotContainsString('stays as declared', $tester->getDisplay());
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

    // Every row the site declared is answered with the site's own title and
    // source, so an invented path can only ride on a row past the end of the
    // declarations. That row is what decides where a file is written.
    public function testARowNamingAPatchTheSiteNeverDeclaredIsRefused(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->inGit();
        $plan = self::plan('conflicts', ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true]);
        $extra = $plan['plan']['patches'][0];
        $extra['title'] = 'Not what the site declared';
        $extra['source'] = 'web/sites/default/settings.php';
        $plan['plan']['patches'][] = $extra;

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

        $tester = $this->drive($site, ['--decisions' => $site->root.'/decisions.json', '--dry-run' => true]);

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

        $tester = $this->drive($site, ['--decisions' => $site->root.'/decisions.json', '--dry-run' => true]);

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
        $tester = $this->drive($site, ['--decisions' => $site->root.'/decisions.json', '--force' => true], self::plan('applies', [
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
        $tester = $this->drive($site, ['--decisions' => $site->root.'/decisions.json', '--force' => true], self::plan('conflicts', [
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

        $tester = $this->drive($site, ['--decisions' => $site->root.'/decisions.json', '--force' => true], self::plan('applies', [
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

        $tester = $this->drive($site, ['--force' => true, '--format' => 'json'], self::plan('applies', ['status' => 'clean', 'patch' => $diff, 'verified' => true, 'conflicts' => []]));

        self::assertSame('', self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'patch'));
        self::assertSame('patches/webform/fix.patch', self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'path'));
        self::assertSame($diff, $site->read('patches/webform/fix.patch'));
        self::assertSame([['path' => 'patches/webform/fix.patch', 'status' => 'clean']], self::at($tester->getDisplay(), 'written'));
    }

    public function testTheJsonKeepsTheDiffOfARowItRefused(): void
    {
        $site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->inGit()->edits('patches/webform/fix.patch');
        $diff = "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+resolved\n";

        // The patch file has uncommitted edits and no --force: its write is refused.
        $tester = $this->drive($site, ['--format' => 'json'], self::plan('applies', ['status' => 'clean', 'patch' => $diff, 'verified' => true, 'conflicts' => []]));

        self::assertSame($diff, self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'patch'));
        self::assertNull(self::at($tester->getDisplay(), 'plan', 'patches', 0, 'result', 'reroll', 'path'));
        self::assertCount(1, (array) self::at($tester->getDisplay(), 'refused'));
    }
}
