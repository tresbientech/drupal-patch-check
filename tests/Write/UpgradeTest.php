<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Write\Upgrade;

#[CoversClass(Upgrade::class)]
class UpgradeTest extends TestCase
{
    /**
     * @param array<string, string> $provenance
     *
     * @return array{package: string, title: string, source: string, provenance: array<string, string>}
     */
    private static function declared(string $package, string $title, string $source, array $provenance = []): array
    {
        return ['package' => $package, 'title' => $title, 'source' => $source, 'provenance' => $provenance];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        return (array) \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testEveryDeclarationMovesIntoTheFileInTheExpandedShape(): void
    {
        $file = Upgrade::patchesFile([
            self::declared('drupal/webform', 'Fix a', 'patches/a.patch'),
            self::declared('drupal/webform', 'Fix b', 'patches/b.patch'),
            self::declared('drupal/core', 'Menu cache', 'patches/c.patch'),
        ], []);

        self::assertSame(['patches' => [
            'drupal/webform' => [
                ['description' => 'Fix a', 'url' => 'patches/a.patch'],
                ['description' => 'Fix b', 'url' => 'patches/b.patch'],
            ],
            'drupal/core' => [
                ['description' => 'Menu cache', 'url' => 'patches/c.patch'],
            ],
        ]], self::decode($file));
        self::assertStringEndsWith("\n", $file);
    }

    // 1.x guessed the level per patch, so a patch that needs one 2.x would
    // not pick carries it.
    public function testOnlyThePatchesThatNeedADepthCarryOne(): void
    {
        $file = Upgrade::patchesFile([
            self::declared('drupal/core', 'Menu cache', 'patches/c.patch'),
            self::declared('drupal/webform', 'Fix a', 'patches/a.patch'),
        ], ["drupal/core\0Menu cache" => 0]);

        $patches = self::decode($file)['patches'];
        self::assertSame(0, $patches['drupal/core'][0]['depth']);
        self::assertArrayNotHasKey('depth', $patches['drupal/webform'][0]);
    }

    public function testACopiedPatchKeepsItsRecord(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'];

        $file = Upgrade::patchesFile([self::declared('drupal/webform', 'Fix a', 'patch/webform/mr940.diff', $record)], []);

        self::assertSame(['drupatch' => $record], self::decode($file)['patches']['drupal/webform'][0]['extra']);
    }

    /**
     * A site as 1.x leaves it: declarations inline, a setting 2.x renames, one
     * it drops, and a key that belongs to nobody here.
     */
    private const SITE = <<<'JSON'
        {
          "name": "test/site",
          "require": {
            "drupal/core": "^11.4"
          },
          "extra": {
            "patches": {
              "drupal/webform": {
                "Fix a": "patches/a.patch"
              }
            },
            "patches-ignore": {
              "drupal/webform": {
                "drupal/core": ["patches/x.patch"]
              }
            },
            "enable-patching": true,
            "drupal-scaffold": {
              "locations": {
                "web-root": "web/"
              }
            }
          }
        }
        JSON;

    public function testTheDeclarationsLeaveComposerJsonAndTheKeyPointsAtTheFile(): void
    {
        $extra = self::decode(self::SITE)['extra'];

        $updated = self::decode(Upgrade::intoComposerJson(self::SITE, $extra, 'patches.json'));

        self::assertArrayNotHasKey('patches', $updated['extra']);
        self::assertSame('patches.json', $updated['extra']['composer-patches']['patches-file']);
    }

    public function testARenamedSettingKeepsItsValueUnderTheNewName(): void
    {
        $extra = self::decode(self::SITE)['extra'];

        $updated = self::decode(Upgrade::intoComposerJson(self::SITE, $extra, 'patches.json'));

        self::assertArrayNotHasKey('patches-ignore', $updated['extra']);
        self::assertSame(
            ['drupal/webform' => ['drupal/core' => ['patches/x.patch']]],
            $updated['extra']['composer-patches']['ignore-dependency-patches']
        );
    }

    public function testADroppedKeyIsGoneAndAKeyThatIsNoneOfOursStays(): void
    {
        $extra = self::decode(self::SITE)['extra'];

        $updated = self::decode(Upgrade::intoComposerJson(self::SITE, $extra, 'patches.json'));

        self::assertArrayNotHasKey('enable-patching', $updated['extra']);
        self::assertSame(['locations' => ['web-root' => 'web/']], $updated['extra']['drupal-scaffold']);
    }

    public function testTheRequirementIsRaisedAndEveryOtherKeyStays(): void
    {
        $extra = self::decode(self::SITE)['extra'];

        $text = Upgrade::intoComposerJson(self::SITE, $extra, 'patches.json');
        $updated = self::decode($text);

        self::assertSame('^2', $updated['require']['cweagans/composer-patches']);
        self::assertSame('^11.4', $updated['require']['drupal/core']);
        self::assertSame('test/site', $updated['name']);
        self::assertStringContainsString('  "name": "test/site"', $text, 'the file keeps its own indentation');
    }
}
