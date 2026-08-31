<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Conflict;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

        $got = Decisions::onDisk($this->root, $patches, []);

        self::assertCount(1, $got[0]);
        self::assertSame('src/Form.php', $got[0][0]['file']);
        self::assertSame('  $decided = TRUE;', $got[0][0]['text'] ?? '');
    }

    public function testAPatchWithNoConflictFileYieldsNothing(): void
    {
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, []));
    }

    public function testAPatchDeclaredAsAUrlIsFoundAtItsAdoptedPath(): void
    {
        $this->put('patches/webform/2821158-12.conflict.patch', self::decided('src/Form.php', 0, 'decided'));
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'https://www.drupal.org/files/issues/2024-01-02/2821158-12.patch']];

        $got = Decisions::onDisk($this->root, $patches, []);

        self::assertCount(1, $got[0]);
        self::assertSame('decided', $got[0][0]['text'] ?? '');
    }

    public function testAFileWithNoDecidedRegionYieldsNothing(): void
    {
        $this->put('patches/webform/fix.conflict.patch', "# drupatch region 0 a.php\n<<<<<<< release a.php:1\na\n=======\nb\n>>>>>>> patch\n# drupatch end 0 a.php\n");
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, []));
    }

    public function testOnlyThePackagesAskedForAreRead(): void
    {
        $this->put('patches/webform/fix.conflict.patch', self::decided('a.php', 0, 'decided'));
        $this->put('patches/paragraphs/fix.conflict.patch', self::decided('b.php', 0, 'decided'));
        $patches = [
            ['package' => 'drupal/webform', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch'],
            ['package' => 'drupal/paragraphs', 'title' => 'Fix', 'source' => 'patches/paragraphs/fix.patch'],
        ];

        $got = Decisions::onDisk($this->root, $patches, ['webform']);

        self::assertArrayHasKey(0, $got);
        self::assertArrayNotHasKey(1, $got);
    }

    public function testASourceLeavingTheSiteRootIsSkipped(): void
    {
        $patches = [['package' => 'drupal/webform', 'title' => 'Fix', 'source' => '../outside/fix.patch']];

        self::assertSame([], Decisions::onDisk($this->root, $patches, []));
    }
}
