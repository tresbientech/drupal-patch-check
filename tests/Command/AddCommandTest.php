<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use ArrayObject;
use Closure;
use Composer\Composer;
use Composer\Console\Application;
use Composer\Factory;
use Composer\IO\NullIO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Site;
use TresBienTech\Drupatch\Tests\StubHost;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What an add run refuses before it asks any host anything, and what a dry
 * run asks and leaves. A real run copies, judges and applies, which needs
 * drupalcode and the patch manager installed, so the scratch site exercises it.
 */
#[CoversClass(AddCommand::class)]
class AddCommandTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940';

    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    private ?ComposerStub $composer = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->composer?->leave();
        $this->site = null;
        $this->server = null;
        $this->composer = null;
    }

    // A dry run prints the verdict the service gives. The clean re-roll in
    // that answer lands nowhere, and no manager command runs.
    public function testADryRunAsksTheServiceAndWritesNothing(): void
    {
        $this->composer = $composer = new ComposerStub();
        $this->server = $server = new PlanServer(['target_core' => '', 'core_installed' => '10.6.9', 'counts' => [], 'rows' => [], 'plan' => ['counts' => [], 'patches' => [[
            'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'conflicts',
            'result' => ['reroll' => ['status' => 'clean', 'patch' => "new diff\n", 'verified' => true]],
        ]]]]);
        $this->site = $site = (new SiteFixture())->withManager('2.0.0');
        $entered = $site->enter($server->endpoint);
        $declared = $site->read('composer.json');

        $tester = self::onDrupalcode($entered, ['issue' => self::MR, '--dry-run' => true], self::drupalcode());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($server->request()['reroll'] ?? null, 'the service was asked for a re-roll');
        self::assertSame($declared, $site->read('composer.json'));
        self::assertDirectoryDoesNotExist($site->root.'/'.Vendoring::DIRECTORY, 'nothing was copied');
        self::assertSame([], $composer->commands());
    }

    // 2.x applies with git apply alone, so it refuses a patch only a lenient
    // apply takes. With no re-roll to declare in its place, the run declares
    // nothing and runs no manager command.
    public function testAStrictRefusalWithNoReRollIsNotDeclared(): void
    {
        $this->composer = $composer = new ComposerStub();
        $this->server = new PlanServer(['target_core' => '', 'core_installed' => '10.6.9', 'counts' => [], 'rows' => [], 'plan' => ['counts' => ['applies' => 1], 'patches' => [[
            'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'applies',
            'result' => ['fuzzy' => true, 'strict_refused' => 'context drifted, your patch manager still applies it'],
        ]]]]);
        $this->site = $site = (new SiteFixture())->withManager('2.0.0');
        $entered = $site->enter($this->server->endpoint);
        $declared = $site->read('composer.json');

        $tester = self::onDrupalcode($entered, ['issue' => self::MR, '--force' => true], self::drupalcode());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('refused by 2.x', $tester->getDisplay());
        self::assertSame($declared, $site->read('composer.json'), 'nothing was declared');
        self::assertSame([], $composer->commands());
    }

    /**
     * An add run whose drupalcode answers come from the stub host.
     *
     * @param array<string, string|bool>        $input
     * @param array<string, array{int, string}> $hosts what drupalcode answers
     */
    private static function onDrupalcode(Composer $entered, array $input, array $hosts): CommandTester
    {
        $command = new class($hosts) extends AddCommand {
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
        $command->setComposer($entered);
        $command->setApplication(new Application());
        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * What drupalcode answers about merge request 940, the issue fork it was pushed from, and its diff.
     *
     * @return array<string, array{int, string}>
     */
    private static function drupalcode(): array
    {
        return [
            'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940' => [200, (string) \json_encode(['source_project_id' => 243137, 'title' => 'Fix the alter hook', 'sha' => 'bbb', 'diff_refs' => ['base_sha' => 'aaa', 'head_sha' => 'bbb', 'start_sha' => 'aaa']])],
            'https://git.drupalcode.org/api/v4/projects/243137' => [200, (string) \json_encode(['path_with_namespace' => 'issue/webform-3521733'])],
            'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff' => [200, "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+upstream\n"],
        ];
    }

    private function drive(string $reference, string $manager = '2.0.0'): CommandTester
    {
        return $this->driveWith($reference, [], $manager);
    }

    /**
     * @param array<string, string>       $options
     * @param ?Closure(SiteFixture): void $edit    run on the site once it is written, before the command
     */
    private function driveWith(string $reference, array $options, string $manager = '2.0.0', ?SiteFixture $site = null, ?Closure $edit = null): CommandTester
    {
        $this->site = ($site ?? new SiteFixture())->withManager($manager);
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');
        if (null !== $edit) {
            $edit($this->site);
        }

        $command = new AddCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute(['issue' => $reference] + $options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTheCommandTakesAnIssueAndADryRun(): void
    {
        $definition = (new AddCommand())->getDefinition();

        self::assertTrue($definition->hasArgument('issue'));
        self::assertTrue($definition->getArgument('issue')->isRequired());
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertFalse($definition->getOption('dry-run')->acceptValue());
    }

    // patches-relock and patches-repatch exist on 2.x alone, so there is
    // nothing to hand the work to on 1.x.
    public function testASiteOnTheOneLineIsRefusedAndSentToTheUpgrade(): void
    {
        $tester = $this->drive(self::MR.'.diff', '1.7.3');

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString(AddCommand::NEEDS_TWO, $tester->getDisplay());
        self::assertStringContainsString('composer '.Manager::UPGRADE, $tester->getDisplay());
    }

    public function testASiteWithNoManagerIsRefusedTheSameWay(): void
    {
        $tester = $this->drive(self::MR.'.diff', '');

        self::assertStringContainsString(AddCommand::NEEDS_TWO, $tester->getDisplay());
    }

    public function testAnythingThatNamesNoIssueAndNoRequestIsRefused(): void
    {
        foreach ([
            'https://github.com/drupal/webform/pull/12.patch',
            'https://www.drupal.org/project/webform/issues/',
            'patches/local.patch',
            '3521733',
        ] as $reference) {
            $tester = $this->drive($reference);

            self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode(), $reference);
            self::assertStringContainsString('points at no drupal.org issue and no merge request', $tester->getDisplay(), $reference);
            $this->site?->leave();
            $this->site = null;
        }
    }

    // The merge request names its project, so the run says so before it
    // reaches a host.
    public function testAPackageTheSiteDoesNotInstallIsRefused(): void
    {
        $tester = $this->drive('https://git.drupalcode.org/project/token/-/merge_requests/12.diff');

        self::assertStringContainsString('this site does not install drupal/token, so there is nothing to patch', $tester->getDisplay());
    }

    // A person copies the URL the page shows: drupal.org's anchor on the
    // comment form, the merge request without the extension a declaration
    // names it by, GitLab's tab in the query.
    public function testAPastedUrlReachesTheRequestItNames(): void
    {
        foreach ([
            'https://www.drupal.org/project/token/issues/2868712#new',
            'https://git.drupalcode.org/project/token/-/merge_requests/12',
            'https://git.drupalcode.org/project/token/-/merge_requests/12.diff?tab=diffs',
        ] as $reference) {
            $tester = $this->drive($reference);

            self::assertStringContainsString('this site does not install drupal/token', $tester->getDisplay(), $reference);
            $this->site?->leave();
            $this->site = null;
        }
    }

    // Core's issues live under the project drupal, and composer installs core
    // as drupal/core.
    public function testACoreIssueNamesDrupalCoreWhenTheSiteDoesNotInstallIt(): void
    {
        $tester = $this->drive('https://www.drupal.org/project/drupal/issues/2868712#new');

        self::assertStringContainsString('this site does not install drupal/core, so there is nothing to patch', $tester->getDisplay());
    }

    // A site that installs drupal/core passes the install check. The issue is
    // searched under the project drupal, and the copy lands under patch/core.
    public function testACoreIssueIsAddedToDrupalCore(): void
    {
        $this->composer = $composer = new ComposerStub();
        $this->server = $server = new PlanServer(['target_core' => '', 'core_installed' => '11.2.5', 'counts' => [], 'rows' => [], 'plan' => ['counts' => ['applies' => 1], 'patches' => [[
            'package' => 'drupal/core', 'project' => 'drupal', 'version' => '11.2.5', 'source' => '', 'verdict' => 'applies',
        ]]]]);
        $this->site = $site = (new SiteFixture())->installsCore('11.2.5')->withManager('2.0.0');
        $entered = $site->enter($server->endpoint);
        $declared = $site->read('composer.json');

        $tester = self::onDrupalcode($entered, ['issue' => 'https://www.drupal.org/project/drupal/issues/2868712#new', '--dry-run' => true], self::coreDrupalcode());

        self::assertSame(Plan::CLEAN, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('drupal/core, issue 2868712', $tester->getDisplay());
        self::assertStringContainsString('patch/core/mr8123.diff', $tester->getDisplay());
        self::assertSame(['drupal/core'], \array_column((array) ($server->request()['patch_config'] ?? []), 'package'));
        self::assertSame($declared, $site->read('composer.json'));
        self::assertSame([], $composer->commands());
    }

    /**
     * What drupalcode answers about core issue 2868712: its one merge request, the issue fork it was pushed from, and its diff.
     *
     * @return array<string, array{int, string}>
     */
    private static function coreDrupalcode(): array
    {
        return [
            'https://git.drupalcode.org/api/v4/projects/project%2Fdrupal/merge_requests?state=all&per_page=50&search=2868712' => [200, (string) \json_encode([['iid' => 8123, 'title' => 'Fix the menu cache', 'target_branch' => '11.x', 'draft' => false, 'state' => 'opened', 'updated_at' => '2026-09-09T00:00:00Z', 'source_project_id' => 180123]])],
            'https://git.drupalcode.org/api/v4/projects/180123' => [200, (string) \json_encode(['path_with_namespace' => 'issue/drupal-2868712'])],
            'https://git.drupalcode.org/api/v4/projects/project%2Fdrupal/merge_requests/8123' => [200, (string) \json_encode(['source_project_id' => 180123, 'title' => 'Fix the menu cache', 'sha' => 'bbb', 'diff_refs' => ['base_sha' => 'aaa', 'head_sha' => 'bbb', 'start_sha' => 'aaa']])],
            'https://git.drupalcode.org/project/drupal/-/compare/aaa...bbb?format=diff' => [200, "diff --git a/core/x b/core/x\n--- a/core/x\n+++ b/core/x\n@@ -1 +1 @@\n-a\n+upstream\n"],
        ];
    }

    // The lock says which packages the service can judge (ADR 0003). A fork
    // of a drupal.org project keeps the name and has no release behind it, so
    // the refusal comes before git, any host and the service.
    public function testAPackageInstalledFromAForkIsRefusedBeforeAnyHost(): void
    {
        $tester = $this->driveWith(self::MR.'.diff', [], site: (new SiteFixture())->installsFromAFork());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('this site installs drupal/webform from outside drupal.org, so the service cannot judge a patch on it', $tester->getDisplay());
        self::assertStringNotContainsString(WorkingTree::NOT_A_CHECKOUT, $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->site->root.'/'.Vendoring::DIRECTORY, 'nothing was copied');
        self::assertStringNotContainsString('mr940', $this->site->read('composer.json'), 'nothing was declared');
    }

    // The run judges the one patch it adds, so the patches the site already
    // declares are never read.
    public function testTheDeclaredPatchesAreNotReadAtAll(): void
    {
        $asked = new ArrayObject();
        $this->site = (new SiteFixture())->declares('Alter hook', 'https://www.drupal.org/files/issues/a.patch')->withManager('2.0.0');
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');
        $root = $this->site->root;

        $command = new class($root, $asked) extends AddCommand {
            /**
             * @param ArrayObject<int, string> $asked
             */
            public function __construct(private readonly string $root, private readonly ArrayObject $asked)
            {
                parent::__construct();
            }

            protected function site(): Site
            {
                return Site::at($this->requireComposer(), new NullIO(), $this->root.'/composer.json', StubHost::fetch([], $this->asked));
            }
        };
        $command->setComposer($composer);
        $command->setApplication(new Application());
        (new CommandTester($command))->execute(['issue' => self::MR.'.diff'], ['capture_stderr_separately' => true]);

        self::assertCount(0, $asked, 'no declared patch was read');
    }

    // The document the declaration lands in is asked about before the issue
    // is looked up, so a refusal leaves the site as it found it.
    public function testAnEditedPatchConfigIsRefusedBeforeAnyHostOrCopy(): void
    {
        $tester = $this->driveWith(self::MR.'.diff', [], site: (new SiteFixture())->inGit(), edit: static function (SiteFixture $site): void {
            $decoded = (array) \json_decode($site->read('composer.json'), true);
            $decoded['extra']['patches']['drupal/webform']['Local'] = 'patches/local.patch';
            $site->write('composer.json', (string) \json_encode($decoded, \JSON_PRETTY_PRINT));
        });

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json has uncommitted changes to its patches; commit them or pass --force', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->site->root.'/'.Vendoring::DIRECTORY, 'nothing was copied');
    }

    public function testASiteGitCannotReadIsRefusedBeforeAnyHostOrCopy(): void
    {
        $tester = $this->drive(self::MR.'.diff');

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json: '.WorkingTree::NOT_A_CHECKOUT.'; pass --force', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->site->root.'/'.Vendoring::DIRECTORY, 'nothing was copied');
    }

    public function testTheCommandTakesMrForARunThatCannotAsk(): void
    {
        $option = (new AddCommand())->getDefinition()->getOption('mr');

        self::assertTrue($option->isValueRequired());
        self::assertFalse($option->isArray());
    }

    // The number is a merge request's own, so anything else is refused
    // before a host is asked.
    public function testAnMrThatIsNoNumberIsRefused(): void
    {
        $tester = $this->driveWith('https://www.drupal.org/project/webform/issues/3521733', ['--mr' => 'latest'], site: (new SiteFixture())->inGit());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('--mr takes a merge request number; latest is not one', $tester->getDisplay());
    }

    // The argument already names one, so a --mr naming another is a person
    // asking for two things at once.
    public function testAnMrThatDisagreesWithTheArgumentIsRefused(): void
    {
        $tester = $this->driveWith(self::MR.'.diff', ['--mr' => '912'], site: (new SiteFixture())->inGit());

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('the argument gives merge request 940, and --mr gives 912', $tester->getDisplay());
    }
}
