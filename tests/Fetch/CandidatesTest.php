<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Fetch;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Fetch\Candidates;
use TresBienTech\Drupatch\Tests\Scratch;

/**
 * What the site's own vendor directory says about core, read with no repository and no network.
 */
#[CoversClass(Candidates::class)]
class CandidatesTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ('' !== $this->root) {
            Scratch::remove($this->root);
        }
        $this->root = '';
    }

    public function testAnInstalledReleaseNamesTheCoreItDeclares(): void
    {
        $composer = $this->site([
            ['name' => 'drupal/webform', 'require' => ['drupal/core' => '^10.3 || ^11']],
            ['name' => 'drupal/token', 'require' => ['drupal/core' => '^10 || ^11']],
        ]);

        $declared = Candidates::declaredCore($composer, ['drupal/webform' => '6.2.9', 'drupal/token' => '1.15.0']);

        self::assertSame(['drupal/webform' => '^10.3 || ^11', 'drupal/token' => '^10 || ^11'], $declared);
    }

    // The request is about the checkable packages, so a package nobody asked
    // about is left out whatever it declares.
    public function testAPackageNobodyAskedAboutIsLeftOut(): void
    {
        $composer = $this->site([
            ['name' => 'drupal/webform', 'require' => ['drupal/core' => '^11']],
            ['name' => 'drupal/acme', 'require' => ['drupal/core' => '^10']],
        ]);

        self::assertSame(['drupal/webform' => '^11'], Candidates::declaredCore($composer, ['drupal/webform' => '6.2.9']));
    }

    public function testAReleaseThatRequiresNoCoreNamesNothing(): void
    {
        $composer = $this->site([['name' => 'drupal/webform', 'require' => ['php' => '>=8.1']]]);

        self::assertSame([], Candidates::declaredCore($composer, ['drupal/webform' => '6.2.9']));
    }

    /**
     * A site whose vendor directory holds these releases.
     *
     * @param list<array{name: string, require: array<string, string>}> $installed
     */
    private function site(array $installed): Composer
    {
        $this->root = \sys_get_temp_dir().'/drupatch-candidates-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root.'/vendor/composer', 0o777, true);
        \file_put_contents($this->root.'/composer.json', (string) \json_encode(['name' => 'site/site']));
        $packages = [];
        foreach ($installed as $package) {
            // Composer drops an installed package whose directory is gone.
            \mkdir($this->root.'/vendor/'.$package['name'], 0o777, true);
            $packages[] = $package + ['version' => '6.2.9', 'version_normalized' => '6.2.9.0', 'type' => 'drupal-module'];
        }
        \file_put_contents($this->root.'/vendor/composer/installed.json', (string) \json_encode(['packages' => $packages, 'dev' => false]));

        return (new Factory())->createComposer(new NullIO(), $this->root.'/composer.json', true, $this->root);
    }
}
