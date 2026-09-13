<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Tests\Scratch;
use TresBienTech\Drupatch\Write\Declarations;

/**
 * The two documents a manager upgrade writes: the patches file the site moves into, and the composer.json that points at it.
 */
#[CoversClass(Declarations::class)]
class MovedDocumentsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-moved-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

    /**
     * @param array<string, string> $provenance
     */
    private static function declared(string $package, string $title, string $source, array $provenance = []): Declaration
    {
        return new Declaration($package, $title, $source, 'composer.json', false, $provenance);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        return (array) \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * The patches file a move over these declarations writes.
     *
     * @param list<Declaration>  $declarations
     * @param array<string, int> $depths
     */
    private function patchesFile(array $declarations, array $depths): string
    {
        Declarations::writeMove($this->root, 'patches.json', $declarations, $depths, "{}\n");

        return (string) \file_get_contents($this->root.'/patches.json');
    }

    public function testEveryDeclarationMovesIntoTheFileInTheExpandedShape(): void
    {
        $file = $this->patchesFile([
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
        $file = $this->patchesFile([
            self::declared('drupal/core', 'Menu cache', 'patches/c.patch'),
            self::declared('drupal/webform', 'Fix a', 'patches/a.patch'),
        ], [Declaration::keyOf('drupal/core', 'Menu cache') => 0]);

        $patches = self::decode($file)['patches'];
        self::assertSame(0, $patches['drupal/core'][0]['depth']);
        self::assertArrayNotHasKey('depth', $patches['drupal/webform'][0]);
    }

    public function testACopiedPatchKeepsItsRecord(): void
    {
        $record = ['mr' => 'https://git.drupalcode.org/project/webform/-/merge_requests/940', 'head' => 'bbb'];

        $file = $this->patchesFile([self::declared('drupal/webform', 'Fix a', 'patch/webform/mr940.diff', $record)], [Declaration::keyOf('drupal/webform', 'Fix a') => 0]);

        self::assertSame(['description', 'url', 'depth', 'extra'], \array_keys(self::decode($file)['patches']['drupal/webform'][0]), 'the depth sits beside the source, the record last');
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

    /**
     * The site's composer.json after the move, as text.
     */
    private function moved(): string
    {
        \file_put_contents($this->root.'/composer.json', self::SITE);

        return Declarations::movedComposerJson($this->root, self::decode(self::SITE)['extra'], 'patches.json');
    }

    public function testTheDeclarationsLeaveComposerJsonAndTheKeyPointsAtTheFile(): void
    {
        $updated = self::decode($this->moved());

        self::assertArrayNotHasKey('patches', $updated['extra']);
        self::assertSame('patches.json', $updated['extra']['composer-patches']['patches-file']);
    }

    public function testARenamedSettingKeepsItsValueUnderTheNewName(): void
    {
        $updated = self::decode($this->moved());

        self::assertArrayNotHasKey('patches-ignore', $updated['extra']);
        self::assertSame(
            ['drupal/webform' => ['drupal/core' => ['patches/x.patch']]],
            $updated['extra']['composer-patches']['ignore-dependency-patches']
        );
    }

    public function testADroppedKeyIsGoneAndAKeyThatIsNoneOfOursStays(): void
    {
        $updated = self::decode($this->moved());

        self::assertArrayNotHasKey('enable-patching', $updated['extra']);
        self::assertSame(['locations' => ['web-root' => 'web/']], $updated['extra']['drupal-scaffold']);
    }

    public function testTheRequirementIsRaisedAndEveryOtherKeyStays(): void
    {
        $text = $this->moved();
        $updated = self::decode($text);

        self::assertSame('^2', $updated['require']['cweagans/composer-patches']);
        self::assertSame('^11.4', $updated['require']['drupal/core']);
        self::assertSame('test/site', $updated['name']);
        self::assertStringContainsString('  "name": "test/site"', $text, 'the file keeps its own indentation');
    }
}
