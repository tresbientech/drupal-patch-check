<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Settings;

#[CoversClass(Settings::class)]
class SettingsTest extends TestCase
{
    public function testOnlyTheKeysTheSiteCarriesAreNamed(): void
    {
        $extra = ['patches-ignore' => ['drupal/core' => []], 'patches' => []];

        self::assertSame(['patches-ignore' => 'ignore-dependency-patches'], Settings::renamed($extra));
        self::assertSame([], Settings::dropped($extra));
    }

    public function testTheKeysTwoReadsNoMoreAreNamedWithTheirReason(): void
    {
        $extra = ['patchLevel' => ['drupal/core' => '-p2'], 'enable-patching' => true];

        self::assertSame([
            'patchLevel' => 'a measured depth replaces it',
            'enable-patching' => '2.x reads it no more',
        ], Settings::dropped($extra));
    }

    public function testASiteCarryingNoneOfThemChangesNoSetting(): void
    {
        self::assertSame([], Settings::renamed(['patches' => []]));
        self::assertSame([], Settings::dropped(['patches' => []]));
    }

    // 2.x applies at 1 unless the definition or its package says otherwise, so
    // a measured 1 is nothing to write down.
    public function testADepthMatchingTheDefaultIsNotRecorded(): void
    {
        self::assertNull(Settings::depthFor('drupal/webform', 1));
        self::assertNull(Settings::depthFor('drupal/webform', null));
    }

    public function testADepthTheDefaultDoesNotGiveIsRecorded(): void
    {
        self::assertSame(0, Settings::depthFor('drupal/webform', 0));
        self::assertSame(2, Settings::depthFor('drupal/webform', 2));
    }

    // The manager ships its own default of 2 for core, so a core patch
    // applying at 2 records nothing and one applying at 1 records it.
    public function testCoreDefaultsToTwo(): void
    {
        self::assertNull(Settings::depthFor('drupal/core', 2));
        self::assertSame(1, Settings::depthFor('drupal/core', 1));
        self::assertSame(0, Settings::depthFor('drupal/core', 0));
    }
}
