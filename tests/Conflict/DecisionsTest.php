<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Conflict;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Scope;
use TresBienTech\Drupatch\Write\Decisions;

#[CoversClass(Decisions::class)]
final class DecisionsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-decisions-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->root.'/*/*/*') as $file) {
            if (\is_string($file) && \is_file($file)) {
                \unlink($file);
            }
        }
        foreach ((array) \glob($this->root.'/*/*') as $file) {
            if (\is_string($file) && \is_file($file)) {
                \unlink($file);
            }
        }
        foreach ((array) \glob($this->root.'/*') as $file) {
            if (\is_string($file) && \is_file($file)) {
                \unlink($file);
            }
        }
    }

    private function put(string $path, string $body): void
    {
        $full = $this->root.'/'.$path;
        if (!\is_dir(\dirname($full))) {
            \mkdir(\dirname($full), 0o777, true);
        }
        \file_put_contents($full, $body);
    }

    private static function decided(string $file, int $region, string $text): string
    {
        return "# drupatch region {$region} {$file}\n{$text}\n# drupatch end {$region} {$file}\n";
    }

    public function testAResolvedFileBesideItsPatchIsFound(): void
    {
        $this->put('patches/webform/fix.conflict.patch', self::decided('src/Form.php', 0, '  $decided = TRUE;'));
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch']];

        $got = Decisions::onDisk($this->root, $patches, Scope::whole());

        self::assertCount(1, $got[0]);
        self::assertSame('src/Form.php', $got[0][0]['file']);
        self::assertSame('  $decided = TRUE;', $got[0][0]['text'] ?? '');
    }

    public function testAPatchWithNoConflictFileYieldsNothing(): void
    {
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, Scope::whole()));
    }

    public function testAPatchDeclaredAsAUrlIsFoundAtItsAdoptedPath(): void
    {
        $this->put('patches/webform/2821158-12.conflict.patch', self::decided('src/Form.php', 0, 'decided'));
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'https://www.drupal.org/files/issues/2024-01-02/2821158-12.patch']];

        $got = Decisions::onDisk($this->root, $patches, Scope::whole());

        self::assertCount(1, $got[0]);
        self::assertSame('decided', $got[0][0]['text'] ?? '');
    }

    public function testAFileWithNoDecidedRegionYieldsNothing(): void
    {
        $this->put('patches/webform/fix.conflict.patch', "# drupatch region 0 a.php\n<<<<<<< release a.php:1\na\n=======\nb\n>>>>>>> patch\n# drupatch end 0 a.php\n");
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, Scope::whole()));
    }

    public function testOnlyThePackagesAskedForAreRead(): void
    {
        $this->put('patches/webform/fix.conflict.patch', self::decided('a.php', 0, 'decided'));
        $this->put('patches/paragraphs/fix.conflict.patch', self::decided('b.php', 0, 'decided'));
        $patches = [
            ['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch'],
            ['package' => 'drupal/paragraphs', 'title' => 'Fix', 'source' => 'patches/paragraphs/fix.patch'],
        ];

        $got = Decisions::onDisk($this->root, $patches, new Scope(['webform'], []));

        self::assertArrayHasKey(0, $got);
        self::assertArrayNotHasKey(1, $got);
    }

    public function testASourceLeavingTheSiteRootIsSkipped(): void
    {
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => '../outside/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, Scope::whole()));
    }

    /**
     * @return list<array{package: string, title: string, source: string}>
     */
    private static function twoPatches(): array
    {
        return [
            ['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch'],
            ['package' => 'drupal/token', 'title' => 'Cache', 'source' => 'https://example.test/cache.patch'],
        ];
    }

    public function testADocumentIsKeyedByThePatchItNamesAndCarriesEachSide(): void
    {
        $json = \json_encode(['decisions' => [
            ['source' => 'https://example.test/cache.patch', 'file' => 'src/Cache.php', 'region' => 1, 'choice' => 'patch'],
            ['source' => 'patches/webform/fix.patch', 'file' => 'src/Form.php', 'region' => 0, 'choice' => 'release'],
            ['source' => 'patches/webform/fix.patch', 'file' => 'src/Form.php', 'region' => 2, 'text' => '  $x = 1;'],
        ]]);

        $got = Decisions::fromDocument((string) $json, self::twoPatches(), Scope::whole());

        self::assertSame([
            1 => [['file' => 'src/Cache.php', 'region' => 1, 'choice' => 'patch']],
            0 => [['file' => 'src/Form.php', 'region' => 0, 'choice' => 'release'], ['file' => 'src/Form.php', 'region' => 2, 'text' => '  $x = 1;']],
        ], $got);
    }

    public function testADocumentNamingAnUndeclaredSourceFailsAndListsTheDeclaredOnes(): void
    {
        $json = \json_encode(['decisions' => [['source' => 'patches/gone.patch', 'file' => 'a.php', 'region' => 0, 'choice' => 'release']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decision 1 names patches/gone.patch, which is not a patch declared in scope; the site declares patches/webform/fix.patch, https://example.test/cache.patch');

        Decisions::fromDocument((string) $json, self::twoPatches(), Scope::whole());
    }

    public function testADocumentNamingAPatchOutsideTheScopeIsRefusedTheSameWay(): void
    {
        $json = \json_encode(['decisions' => [['source' => 'patches/webform/fix.patch', 'file' => 'a.php', 'region' => 0, 'choice' => 'release']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the site declares https://example.test/cache.patch');

        Decisions::fromDocument((string) $json, self::twoPatches(), new Scope(['token'], []));
    }

    public function testADecisionWithNoRegionOrNoSideIsRefused(): void
    {
        $noRegion = \json_encode(['decisions' => [['source' => 'patches/webform/fix.patch', 'file' => 'a.php', 'choice' => 'release']]]);
        try {
            Decisions::fromDocument((string) $noRegion, self::twoPatches(), Scope::whole());
            self::fail('a decision without a region was accepted');
        } catch (RuntimeException $e) {
            self::assertSame('decision 1 for patches/webform/fix.patch needs the file and the region index the conflict reported', $e->getMessage());
        }

        $noSide = \json_encode(['decisions' => [['source' => 'patches/webform/fix.patch', 'file' => 'a.php', 'region' => 0, 'choice' => 'mine']]]);
        try {
            Decisions::fromDocument((string) $noSide, self::twoPatches(), Scope::whole());
            self::fail('a decision with an unknown side was accepted');
        } catch (RuntimeException $e) {
            self::assertSame('decision 1 for patches/webform/fix.patch a.php:0 needs a choice of release or patch, or a text', $e->getMessage());
        }
    }

    public function testADocumentThatIsNotADecisionsListIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one JSON object holding a "decisions" list');

        Decisions::fromDocument('[1, 2]', self::twoPatches(), Scope::whole());
    }

    public function testTheDocumentWinsOverAConflictFileOnTheSameRegionAndSaysSo(): void
    {
        $onDisk = [0 => [['file' => 'src/Form.php', 'region' => 0, 'text' => 'from the file'], ['file' => 'src/Form.php', 'region' => 1, 'delete' => true]]];
        $document = [0 => [['file' => 'src/Form.php', 'region' => 0, 'choice' => 'release']], 1 => [['file' => 'src/Cache.php', 'region' => 0, 'choice' => 'patch']]];

        $merged = Decisions::merge($onDisk, $document);

        self::assertSame([
            0 => [['file' => 'src/Form.php', 'region' => 1, 'delete' => true], ['file' => 'src/Form.php', 'region' => 0, 'choice' => 'release']],
            1 => [['file' => 'src/Cache.php', 'region' => 0, 'choice' => 'patch']],
        ], $merged['decided']);
        self::assertSame([['patch' => 0, 'file' => 'src/Form.php', 'region' => 0]], $merged['overridden']);
    }
}
