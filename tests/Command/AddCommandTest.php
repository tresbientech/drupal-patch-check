<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Closure;
use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\AddCommand;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What an add run refuses before it asks any host anything. The path that
 * copies, judges and applies needs drupalcode and the patch manager
 * installed, so the scratch site exercises it.
 */
#[CoversClass(AddCommand::class)]
class AddCommandTest extends TestCase
{
    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940';

    private ?SiteFixture $site = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->site = null;
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
            self::assertStringContainsString('names no drupal.org issue and no merge request', $tester->getDisplay(), $reference);
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
        self::assertFalse($this->site?->has('patch'), 'nothing was copied');
    }

    public function testASiteGitCannotReadIsRefusedBeforeAnyHostOrCopy(): void
    {
        $tester = $this->drive(self::MR.'.diff');

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json: '.WorkingTree::NOT_A_CHECKOUT.'; pass --force', $tester->getDisplay());
        self::assertFalse($this->site?->has('patch'), 'nothing was copied');
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
        self::assertStringContainsString('the argument names merge request 940, and --mr names 912', $tester->getDisplay());
    }
}
