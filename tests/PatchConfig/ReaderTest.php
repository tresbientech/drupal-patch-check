<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\PatchConfig;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\PatchConfig\Reader;

final class ReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root.'/patches', 0o777, true);
        \file_put_contents($this->root.'/patches/local.patch', "diff --git a/x b/x\n");
    }

    protected function tearDown(): void
    {
        foreach (self::paths($this->root.'/*/*') as $file) {
            @\unlink($file);
        }
        foreach (self::paths($this->root.'/*') as $path) {
            \is_dir($path) ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($this->root);
    }

    /**
     * @return list<string>
     */
    private static function paths(string $pattern): array
    {
        $found = \glob($pattern);

        return false === $found ? [] : $found;
    }

    private function reader(): Reader
    {
        return new Reader($this->root);
    }

    public function testReadsTheInlineMapEveryDrupalSiteUses(): void
    {
        $extra = ['patches' => ['drupal/webform' => ['Fix the alter hook' => 'patches/local.patch']]];

        $resolution = $this->reader()->read($extra);

        self::assertSame([['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => 'patches/local.patch']], $resolution->patches);
        self::assertSame(['patches/local.patch' => "diff --git a/x b/x\n"], $resolution->files);
        self::assertSame([], $resolution->notes);
    }

    public function testReadsAnExternalPatchesFile(): void
    {
        \file_put_contents($this->root.'/patches.json', \json_encode([
            'patches' => ['drupal/webform' => ['From a file' => 'patches/local.patch']],
        ]));
        $extra = ['patches-file' => 'patches.json', 'patches' => ['drupal/core' => ['Inline' => 'https://www.drupal.org/files/issues/a.patch']]];

        $resolution = $this->reader()->read($extra);

        self::assertCount(1, $resolution->patches, 'a patches file replaces the inline map, as the manager does');
        self::assertSame('From a file', $resolution->patches[0]['title']);
        self::assertArrayHasKey('patches/local.patch', $resolution->files);
    }

    public function testReadsAPatchesFileWrittenAsABareMap(): void
    {
        \file_put_contents($this->root.'/patches.json', \json_encode([
            'drupal/webform' => ['Bare map' => 'https://www.drupal.org/files/issues/a.patch'],
        ]));

        $resolution = $this->reader()->read(['patches-file' => 'patches.json']);

        self::assertSame('Bare map', $resolution->patches[0]['title']);
    }

    public function testSaysSoWhenThePatchesFileCannotBeRead(): void
    {
        $resolution = $this->reader()->read(['patches-file' => 'missing.json']);

        self::assertSame([], $resolution->patches);
        self::assertStringContainsString('missing.json', \implode(' ', $resolution->notes));
    }

    public function testReadsEntriesWrittenAsObjects(): void
    {
        $extra = ['patches' => ['drupal/webform' => [
            ['description' => 'A list entry', 'url' => 'https://www.drupal.org/files/issues/a.patch'],
            'Keyed entry' => ['url' => 'patches/local.patch', 'depth' => 2],
        ]]];

        $resolution = $this->reader()->read($extra);

        self::assertSame('A list entry', $resolution->patches[0]['title']);
        self::assertSame('https://www.drupal.org/files/issues/a.patch', $resolution->patches[0]['source']);
        self::assertSame('Keyed entry', $resolution->patches[1]['title']);
        self::assertSame('patches/local.patch', $resolution->patches[1]['source']);
    }

    public function testReadsVaimoStyleEntries(): void
    {
        $extra = ['patches' => ['drupal/webform' => [
            ['label' => 'Vaimo entry', 'source' => 'patches/local.patch', 'level' => 1],
        ]]];

        $resolution = $this->reader()->read($extra);

        self::assertSame([['package' => 'drupal/webform', 'title' => 'Vaimo entry', 'source' => 'patches/local.patch']], $resolution->patches);
    }

    public function testDropsWhatAnIgnoreListIgnores(): void
    {
        $extra = [
            'patches' => ['drupal/webform' => [
                'Keep me' => 'https://www.drupal.org/files/issues/a.patch',
                'Drop me' => 'https://www.drupal.org/files/issues/b.patch',
            ]],
            'patches-ignore' => ['some/dependency' => ['drupal/webform' => ['Drop me' => 'https://www.drupal.org/files/issues/b.patch']]],
        ];

        $resolution = $this->reader()->read($extra);

        self::assertCount(1, $resolution->patches);
        self::assertSame('Keep me', $resolution->patches[0]['title']);
    }

    public function testAStripLevelDoesNotStopAPatchBeingRead(): void
    {
        $extra = [
            'patches' => ['drupal/webform' => ['Fix' => 'patches/local.patch']],
            'patchLevel' => ['drupal/webform' => '-p2'],
        ];

        self::assertCount(1, $this->reader()->read($extra)->patches);
    }

    public function testSaysSoWhenPatchesAreFoundOnlyByDirectoryScan(): void
    {
        $resolution = $this->reader()->read(['patches-search' => 'patches/']);

        self::assertStringContainsString('patches-search', \implode(' ', $resolution->notes));
    }

    public function testNamesAnInstalledManagerItDoesNotRead(): void
    {
        $installed = ['cweagans/composer-patches', 'acme/composer-patches-fork', 'drupal/core'];

        $notes = \implode(' ', $this->reader()->read([], $installed)->notes);

        self::assertStringContainsString('acme/composer-patches-fork', $notes);
        self::assertStringNotContainsString('cweagans/composer-patches is installed', $notes);
    }

    public function testLeavesURLPatchesToTheServer(): void
    {
        $extra = ['patches' => ['drupal/webform' => ['Fix' => 'https://www.drupal.org/files/issues/a.patch']]];

        $resolution = $this->reader()->read($extra);

        self::assertSame([], $resolution->files);
        self::assertFalse($resolution->isEmpty());
    }

    public function testAPathThatDoesNotResolveIsStillDeclared(): void
    {
        $extra = ['patches' => ['drupal/webform' => ['Fix' => 'patches/gone.patch']]];

        $resolution = $this->reader()->read($extra);

        self::assertCount(1, $resolution->patches, 'the plan reports a missing file; the reader does not hide the patch');
        self::assertSame([], $resolution->files);
    }

    public function testRefusesAPathLeavingTheSiteRoot(): void
    {
        $extra = ['patches' => ['drupal/webform' => ['Fix' => '../../etc/hosts']]];

        self::assertSame([], $this->reader()->read($extra)->files);
    }

    public function testASiteWithoutPatchesDeclaresNone(): void
    {
        self::assertTrue($this->reader()->read([])->isEmpty());
        self::assertTrue($this->reader()->read(['patches' => 'patches.json'])->isEmpty());
    }

    public function testTheSameFileDeclaredTwiceIsSentOnce(): void
    {
        $extra = ['patches' => [
            'drupal/webform' => ['Fix' => 'patches/local.patch'],
            'drupal/core' => ['Same file' => 'patches/local.patch'],
        ]];

        $resolution = $this->reader()->read($extra);

        self::assertCount(2, $resolution->patches);
        self::assertCount(1, $resolution->files);
    }
}
