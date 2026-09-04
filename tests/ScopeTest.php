<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Scope;

/**
 * Which declared patches a --package and --patch pair selects.
 */
#[CoversClass(Scope::class)]
class ScopeTest extends TestCase
{
    public function testTheWholeSiteSelectsEveryPatch(): void
    {
        $scope = Scope::whole();

        self::assertTrue($scope->isWhole());
        self::assertTrue($scope->has('drupal/webform', 'patches/webform/fix.patch'));
        self::assertTrue($scope->has('acquia/cohesion', 'https://example.test/a.patch'));
    }

    public function testEitherSpellingOfAPackageInAnyCaseIsTheSamePackage(): void
    {
        $scope = new Scope(['  WebForm  '], []);

        self::assertTrue($scope->hasPackage('drupal/webform'));
        self::assertTrue($scope->hasPackage('webform'));
        self::assertFalse($scope->hasPackage('drupal/token'));
        self::assertSame('webform', Scope::key('drupal/webform'));
        self::assertSame('webform', Scope::key('Webform'));
        self::assertSame('cohesion', Scope::key('acquia/cohesion'));
    }

    public function testAPackageSelectsEveryPatchItDeclares(): void
    {
        $scope = new Scope(['webform'], []);

        self::assertTrue($scope->has('drupal/webform', 'patches/webform/fix.patch'));
        self::assertTrue($scope->has('drupal/webform', 'https://example.test/other.patch'));
        self::assertFalse($scope->has('drupal/token', 'patches/token/fix.patch'));
    }

    public function testASourceSelectsOnePatchAsWritten(): void
    {
        $scope = new Scope([], ['patches/webform/fix.patch']);

        self::assertFalse($scope->isWhole());
        self::assertTrue($scope->has('drupal/webform', 'patches/webform/fix.patch'));
        self::assertFalse($scope->has('drupal/webform', 'patches/webform/other.patch'));
        self::assertFalse($scope->has('drupal/webform', 'Patches/webform/fix.patch'), 'a source is a path, matched exactly');
        self::assertTrue($scope->hasPackage('drupal/token'), 'a source names no package, so every package stays in');
    }

    public function testAPackageAndASourceBothHaveToMatch(): void
    {
        $scope = new Scope(['token'], ['patches/webform/fix.patch']);

        self::assertFalse($scope->has('drupal/webform', 'patches/webform/fix.patch'));
        self::assertFalse($scope->has('drupal/token', 'patches/token/fix.patch'));
        self::assertTrue($scope->has('drupal/token', 'patches/webform/fix.patch'));
    }

    public function testTheSourcesTheSiteDoesNotDeclareAreNamed(): void
    {
        $scope = new Scope([], ['patches/webform/fix.patch', 'patches/gone.patch']);

        self::assertSame(['patches/gone.patch'], $scope->unknownSources(['patches/webform/fix.patch', 'patches/token/fix.patch']));
        self::assertSame([], Scope::whole()->unknownSources([]));
    }
}
