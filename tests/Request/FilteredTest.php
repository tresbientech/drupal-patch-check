<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Request;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Request\Filtered;

final class FilteredTest extends TestCase
{
    private const DRUPAL = 'https://packages.drupal.org/8/downloads';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    public function testAPackageServedByDrupalOrgIsCheckable(): void
    {
        $request = $this->of([], [
            ['name' => 'drupal/webform', 'version' => '6.2.9', 'notification-url' => self::DRUPAL],
        ]);

        self::assertSame(['drupal/webform' => '6.2.9'], $request->packages);
        self::assertSame([], $request->heldBack);
    }

    public function testAForkCarryingADrupalNameIsHeldBack(): void
    {
        // drupal/coder installed from a company fork, so drupal.org has no
        // release matching what is on disk.
        $request = $this->of([], [
            ['name' => 'drupal/coder', 'version' => '8.3.31', 'notification-url' => self::PACKAGIST],
        ]);

        self::assertSame([], $request->packages);
        self::assertSame(['drupal/coder'], $request->heldBack);
    }

    public function testCoresOwnPackagesAreCheckableDespitePackagist(): void
    {
        $request = $this->of([], [
            ['name' => 'drupal/core', 'version' => '11.4.5', 'notification-url' => self::PACKAGIST],
            ['name' => 'drupal/core-recommended', 'version' => '11.4.5', 'notification-url' => self::PACKAGIST],
            ['name' => 'drupal/core-composer-scaffold', 'version' => '11.4.5', 'notification-url' => self::PACKAGIST],
        ]);

        self::assertSame(
            ['drupal/core', 'drupal/core-recommended', 'drupal/core-composer-scaffold'],
            \array_keys($request->packages),
        );
    }

    public function testASubModuleWithNoDistIsCheckable(): void
    {
        // A sub-module carries no dist entry because its parent package
        // provides it; the notification-url is what says where it is from.
        $request = $this->of([], [
            ['name' => 'drupal/domain_access', 'version' => '3.0.1', 'notification-url' => self::DRUPAL],
        ]);

        self::assertSame(['drupal/domain_access' => '3.0.1'], $request->packages);
    }

    public function testAPackageWithNoNotificationUrlIsHeldBack(): void
    {
        $request = $this->of([], [['name' => 'drupal/acme_sso', 'version' => '1.0.0']]);

        self::assertSame([], $request->packages);
        self::assertSame(['drupal/acme_sso'], $request->heldBack);
    }

    public function testANonDrupalPackageIsDroppedWithoutBeingNamed(): void
    {
        $request = $this->of([], [['name' => 'symfony/console', 'version' => '6.4.0', 'notification-url' => self::PACKAGIST]]);

        self::assertSame([], $request->packages);
        self::assertSame([], $request->heldBack, 'a vendor package is not a finding, it is simply not this tool"s business');
    }

    public function testTheRequestCarriesFiveKeysAndNoOthers(): void
    {
        $json = [
            'name' => 'acme/site',
            'description' => 'The ACME public site',
            'license' => 'proprietary',
            'repositories' => [['type' => 'composer', 'url' => 'https://packages.acme-internal.com']],
            'config' => ['sort-packages' => true],
            'scripts' => ['post-install-cmd' => ['./bin/deploy.sh']],
            'require' => ['drupal/webform' => '^6.2', 'acme/private' => '^1.0', 'php' => '>=8.1'],
            'require-dev' => ['drupal/devel' => '^5', 'phpunit/phpunit' => '^10'],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'extra' => [
                'installer-paths' => ['web/modules/contrib/{$name}' => ['type:drupal-module']],
                'patches' => [
                    'drupal/webform' => ['Alter hook' => 'patches/webform.patch'],
                    'acme/private' => ['In-house' => 'patches/private.patch'],
                ],
            ],
        ];
        $request = $this->of($json, [
            ['name' => 'drupal/webform', 'version' => '6.2.9', 'notification-url' => self::DRUPAL],
            ['name' => 'drupal/devel', 'version' => '5.3.2', 'notification-url' => self::DRUPAL],
        ]);

        $sent = \json_decode($request->composerJson, true);
        self::assertIsArray($sent);
        self::assertSame(['require', 'require-dev', 'minimum-stability', 'prefer-stable', 'extra'], \array_keys($sent));
        self::assertSame(['drupal/webform' => '^6.2'], $sent['require']);
        self::assertSame(['drupal/devel' => '^5'], $sent['require-dev']);
        self::assertSame(['patches' => ['drupal/webform' => ['Alter hook' => 'patches/webform.patch']]], $sent['extra']);
        self::assertStringNotContainsString('acme-internal', $request->composerJson);
        self::assertStringNotContainsString('deploy.sh', $request->composerJson);
    }

    public function testTheLockCarriesNamesAndVersionsOnly(): void
    {
        $request = $this->of([], [
            ['name' => 'drupal/webform', 'version' => '6.2.9', 'notification-url' => self::DRUPAL, 'dist' => ['url' => 'https://ftp.drupal.org/x.zip']],
        ], [
            ['name' => 'drupal/devel', 'version' => '5.3.2', 'notification-url' => self::DRUPAL],
        ]);

        self::assertSame([
            'packages' => [['name' => 'drupal/webform', 'version' => '6.2.9']],
            'packages-dev' => [['name' => 'drupal/devel', 'version' => '5.3.2']],
        ], \json_decode($request->composerLock, true));
    }

    public function testAnEmptyRequireIsLeftOutRatherThanSentEmpty(): void
    {
        $request = $this->of(['require' => ['acme/private' => '^1.0']], []);

        self::assertSame('{}', $request->composerJson);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: list<string>}>
     */
    public static function siteFixtures(): iterable
    {
        yield 'acme-one' => ['acme-one', 101, ['drupal/nouislider_js', 'drupal/rat', 'drupal/coder']];
        yield 'acme-two' => ['acme-two', 61, ['drupal/rat', 'drupal/coder']];
    }

    /**
     * @param list<string> $heldBack
     *
     * @dataProvider siteFixtures
     */
    public function testARealSiteKeepsWhatTheServiceCanJudge(string $fixture, int $checkable, array $heldBack): void
    {
        // Two real sites, their module names replaced. The counts and the
        // notification-url of every entry are as they were installed.
        $lock = (string) \file_get_contents(__DIR__.'/fixtures/'.$fixture.'.lock.json');

        $request = Filtered::of('{}', $lock);

        self::assertCount($checkable, $request->packages);
        self::assertSame($heldBack, $request->heldBack);
        self::assertStringNotContainsString('vendor/', $request->composerLock);
    }

    /**
     * @param array<string, mixed>       $json
     * @param list<array<string, mixed>> $packages
     * @param list<array<string, mixed>> $dev
     */
    private function of(array $json, array $packages, array $dev = []): Filtered
    {
        $lock = ['packages' => $packages];
        if ([] !== $dev) {
            $lock['packages-dev'] = $dev;
        }

        return Filtered::of(
            \json_encode($json, \JSON_THROW_ON_ERROR),
            \json_encode($lock, \JSON_THROW_ON_ERROR),
        );
    }
}
