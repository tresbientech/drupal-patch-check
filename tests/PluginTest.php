<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plugin;

final class PluginTest extends TestCase
{
    public function testTheHookStaysOffWhenTheSiteSaysNothing(): void
    {
        self::assertFalse(Plugin::hookEnabled([]));
        self::assertFalse(Plugin::hookEnabled(['drupal-patch-check' => ['endpoint' => 'https://example.com']]));
    }

    public function testASiteTurnsTheHookOnWithATrue(): void
    {
        self::assertTrue(Plugin::hookEnabled(['drupal-patch-check' => ['hook' => true]]));
    }

    public function testFalseReadsTheSameAsSayingNothing(): void
    {
        self::assertFalse(Plugin::hookEnabled(['drupal-patch-check' => ['hook' => false]]));
    }

    // Only true turns it on. A string or a number is a value nobody meant,
    // and sending a site's patches on a guess would be worse than staying quiet.
    public function testAValueThatIsNotTrueLeavesTheHookOff(): void
    {
        self::assertFalse(Plugin::hookEnabled(['drupal-patch-check' => ['hook' => 'true']]));
        self::assertFalse(Plugin::hookEnabled(['drupal-patch-check' => ['hook' => 1]]));
    }

    public function testExtraThatIsNotAMapIsIgnored(): void
    {
        self::assertFalse(Plugin::hookEnabled(['drupal-patch-check' => 'on']));
    }

    public function testTheOldKeyNoLongerTurnsTheHookOn(): void
    {
        self::assertFalse(Plugin::hookEnabled(['drupatch' => ['hook' => true]]));
    }

    // Composer fires the update event for update, require and remove, and
    // the install event for install, so the four commands are covered.
    public function testEveryCommandThatChangesTheTreeIsSubscribedTo(): void
    {
        self::assertSame([
            'post-update-cmd' => 'onPostUpdate',
            'post-install-cmd' => 'onPostInstall',
            'post-package-install' => 'onPackageInstall',
        ], Plugin::getSubscribedEvents());
    }

    // The nudge is for a site that can act on it, so a site already on 2.x
    // and a site with no patch manager are told nothing.
    public function testASiteOnOneIsToldWhichLineToBeOn(): void
    {
        $lines = Plugin::movePrompt(Manager::ofVersion('1.7.3'));

        self::assertNotEmpty($lines);
        self::assertStringContainsString('composer-patches 1.x', $lines[0]);
        self::assertStringContainsString('composer '.Manager::UPGRADE.' --dry-run', \end($lines));
    }

    public function testASiteOnTwoIsToldNothing(): void
    {
        self::assertSame([], Plugin::movePrompt(Manager::ofVersion('2.0.0')));
    }

    public function testASiteWithNoPatchManagerIsToldNothing(): void
    {
        self::assertSame([], Plugin::movePrompt(Manager::ofVersion('')));
    }

    // The disclosure says what leaves the site and how to read it first.
    // Nothing in it is about what the service keeps.
    public function testTheInstallNoticeNamesTheServiceAndTheDryRun(): void
    {
        $notice = \implode(' ', (array) (new ReflectionClass(Plugin::class))->getConstant('NOTICE'));

        self::assertStringContainsString('api.tresbien.tech', $notice);
        self::assertStringContainsString('--dry-run', $notice);
        self::assertStringNotContainsString('cached', $notice);
    }
}
